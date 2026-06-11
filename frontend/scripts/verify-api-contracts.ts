import * as ts from 'typescript'
import { execSync } from 'node:child_process'
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

type ApiMethod = 'get' | 'post' | 'put' | 'patch' | 'delete'

type ApiCall = {
  file: string
  line: number
  method: ApiMethod
  path: string
}

type BackendRoute = {
  method: string
  path: string
  regex: RegExp
}

const frontendRoot = dirname(dirname(fileURLToPath(import.meta.url)))
const repoRoot = dirname(frontendRoot)

const apiMethods = new Set<ApiMethod>(['get', 'post', 'put', 'patch', 'delete'])

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

function routeToRegex(path: string): RegExp {
  let pattern = ''

  for (let index = 0; index < path.length; index += 1) {
    if (path.startsWith('.{_format}', index)) {
      pattern += '(?:\\.[^/]+)?'
      index += '.{_format}'.length - 1
      continue
    }

    if ('{' === path[index]) {
      const endIndex = path.indexOf('}', index)
      if (endIndex > index) {
        pattern += '[^/]+'
        index = endIndex
        continue
      }
    }

    pattern += escapeRegExp(path[index])
  }

  return new RegExp(`^${pattern}$`)
}

function normalizeRequestedPath(rawPath: string): string {
  const path = rawPath.split(/[?#]/, 1)[0].replace(/\{param\}/g, 'sample-value')
  if (path.startsWith('/api/')) {
    return path
  }

  return `/api/${path.replace(/^\//, '')}`
}

function unwrapExpression(node: ts.Expression): ts.Expression {
  let current = node

  while (
    ts.isParenthesizedExpression(current) ||
    ts.isAsExpression(current) ||
    ts.isTypeAssertionExpression(current) ||
    ts.isNonNullExpression(current)
  ) {
    current = current.expression
  }

  return current
}

function expressionToPathFragment(node: ts.Expression): string | null {
  const current = unwrapExpression(node)

  if (ts.isStringLiteralLike(current)) {
    return current.text
  }

  if (ts.isNoSubstitutionTemplateLiteral(current)) {
    return current.text
  }

  if (ts.isTemplateExpression(current)) {
    let result = current.head.text

    for (const span of current.templateSpans) {
      result += '{param}' + span.literal.text
    }

    return result
  }

  if (ts.isBinaryExpression(current) && current.operatorToken.kind === ts.SyntaxKind.PlusToken) {
    const left = expressionToPathFragment(current.left)
    const right = expressionToPathFragment(current.right)

    if (null !== left && null !== right) {
      return left + right
    }
  }

  return null
}

function collectFiles(directory: string): string[] {
  const entries = readdirSync(directory, { withFileTypes: true })
  const files: string[] = []

  for (const entry of entries) {
    const fullPath = join(directory, entry.name)
    if (entry.isDirectory()) {
      files.push(...collectFiles(fullPath))
      continue
    }

    if (entry.isFile() && /\.(tsx?|jsx?)$/.test(entry.name)) {
      files.push(fullPath)
    }
  }

  return files
}

function collectApiCalls(): ApiCall[] {
  const calls: ApiCall[] = []

  for (const filePath of collectFiles(join(frontendRoot, 'src'))) {
    if (filePath.endsWith('.test.ts') || filePath.endsWith('.test.tsx') || filePath.endsWith('.spec.ts') || filePath.endsWith('.spec.tsx')) {
      continue
    }

    const sourceText = readFileSync(filePath, 'utf8')
    const sourceFile = ts.createSourceFile(
      filePath,
      sourceText,
      ts.ScriptTarget.Latest,
      true,
      filePath.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
    )

    const visit = (node: ts.Node): void => {
      if (
        ts.isCallExpression(node) &&
        ts.isPropertyAccessExpression(node.expression) &&
        ts.isIdentifier(node.expression.expression) &&
        'apiClient' === node.expression.expression.text &&
        ts.isIdentifier(node.expression.name) &&
        apiMethods.has(node.expression.name.text as ApiMethod)
      ) {
        const pathArg = node.arguments[0]
        if (pathArg) {
          const path = expressionToPathFragment(pathArg)
          if (null !== path) {
            const position = sourceFile.getLineAndCharacterOfPosition(node.getStart(sourceFile))
            calls.push({
              file: relative(frontendRoot, filePath),
              line: position.line + 1,
              method: node.expression.name.text as ApiMethod,
              path,
            })
          }
        }
      }

      ts.forEachChild(node, visit)
    }

    visit(sourceFile)
  }

  return calls
}

function loadBackendRoutes(): BackendRoute[] {
  const output = execSync('docker compose exec -T php-fpm php bin/console debug:router', {
    cwd: repoRoot,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  })

  const routes: BackendRoute[] = []

  for (const line of output.split(/\r?\n/)) {
    const match = line.match(/^\s*(\S+)\s+(\S+)\s+(.+?)\s*$/)
    if (!match) {
      continue
    }

    const [, , methods, path] = match
    if (!path.startsWith('/api/')) {
      continue
    }

    const regex = routeToRegex(path)
    for (const method of methods.split('|')) {
      routes.push({ method, path, regex })
    }
  }

  return routes
}

function main(): void {
  const calls = collectApiCalls()
  const routes = loadBackendRoutes()
  const failures: string[] = []

  for (const call of calls) {
    const requestedPath = normalizeRequestedPath(call.path)
    const matches = routes.filter(
      (route) => route.method === call.method.toUpperCase() && route.regex.test(requestedPath),
    )

    if (0 === matches.length) {
      failures.push(
        `${call.file}:${call.line} ${call.method.toUpperCase()} ${requestedPath} (from ${call.path})`,
      )
    }
  }

  if (failures.length > 0) {
    console.error('API contract check failed for these frontend calls:')
    for (const failure of failures) {
      console.error(`- ${failure}`)
    }
    process.exitCode = 1
    return
  }

  console.log(`API contract check passed for ${calls.length} calls.`)
}

main()
