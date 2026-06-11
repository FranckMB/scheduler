import { useCallback, useMemo, useRef, useState, type ChangeEvent, type DragEvent } from 'react'
import { useWizardStore } from '@/features/wizard/wizardStore'

interface ImportExcelDialogProps {
  isOpen: boolean
  onClose: () => void
}

interface ParsedTeamRow {
  name: string
  category: string
  number: string
  organization: string
  duplicate: boolean
}

const REQUIRED_HEADERS = ['Nom', 'Catégorie', 'Numéro', 'Organisme']
const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
const XLSX_EXTENSION = '.xlsx'

function normalize(value: string): string {
  return value.trim().toLocaleLowerCase('fr-FR')
}

function columnToIndex(column: string): number {
  let index = 0
  for (const char of column) {
    index = index * 26 + (char.charCodeAt(0) - 64)
  }
  return index - 1
}

function cellValue(cell: Element): string {
  const type = cell.getAttribute('t')
  if (type === 'inlineStr') {
    return cell.getElementsByTagName('is')[0]?.getElementsByTagName('t')[0]?.textContent?.trim() ?? ''
  }

  if (type === 'str') {
    return cell.getElementsByTagName('v')[0]?.textContent?.trim() ?? ''
  }

  if (type === 'b') {
    return '1' === cell.getElementsByTagName('v')[0]?.textContent?.trim() ? 'TRUE' : 'FALSE'
  }

  return cell.getElementsByTagName('v')[0]?.textContent?.trim() ?? ''
}

async function inflateZipEntry(bytes: Uint8Array, method: number): Promise<Uint8Array> {
  if (0 === method) {
    return bytes
  }

  if (8 === method) {
    const stream = new Blob([bytes as unknown as BlobPart]).stream().pipeThrough(new DecompressionStream('deflate-raw'))
    return new Uint8Array(await new Response(stream).arrayBuffer())
  }

  throw new Error(`Unsupported ZIP compression method: ${method}`)
}

async function readFileBuffer(file: File): Promise<ArrayBuffer> {
  if ('arrayBuffer' in file) {
    return file.arrayBuffer()
  }

  return await new Promise<ArrayBuffer>((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => {
      const result = reader.result
      if (result instanceof ArrayBuffer) {
        resolve(result)
      } else {
        reject(new Error('Impossible de lire le fichier Excel.'))
      }
    }
    reader.onerror = () => reject(reader.error ?? new Error('Impossible de lire le fichier Excel.'))
    reader.readAsArrayBuffer(file)
  })
}

async function readXlsxEntries(file: File): Promise<Record<string, string>> {
  const buffer = await readFileBuffer(file)
  const bytes = new Uint8Array(buffer)
  const view = new DataView(buffer)
  const decoder = new TextDecoder('utf-8')
  const entries: Record<string, string> = {}

  let offset = 0
  while (offset + 30 <= bytes.length) {
    if (0x04034b50 !== view.getUint32(offset, true)) {
      break
    }

    const method = view.getUint16(offset + 8, true)
    const compressedSize = view.getUint32(offset + 18, true)
    const fileNameLength = view.getUint16(offset + 26, true)
    const extraFieldLength = view.getUint16(offset + 28, true)
    const fileNameStart = offset + 30
    const fileName = decoder.decode(bytes.slice(fileNameStart, fileNameStart + fileNameLength))
    const dataStart = fileNameStart + fileNameLength + extraFieldLength
    const dataEnd = dataStart + compressedSize
    const entryBytes = bytes.slice(dataStart, dataEnd)
    const inflated = await inflateZipEntry(entryBytes, method)

    entries[fileName] = decoder.decode(inflated)
    offset = dataEnd
  }

  return entries
}

function resolveFirstSheetPath(entries: Record<string, string>): string | null {
  const workbookXml = entries['xl/workbook.xml']
  if (!workbookXml) {
    return entries['xl/worksheets/sheet1.xml'] ? 'xl/worksheets/sheet1.xml' : null
  }

  const workbookDoc = new DOMParser().parseFromString(workbookXml, 'application/xml')
  const sheetNode = workbookDoc.getElementsByTagName('sheet')[0]
  const relId = sheetNode?.getAttribute('r:id')
  if (!relId) {
    return entries['xl/worksheets/sheet1.xml'] ? 'xl/worksheets/sheet1.xml' : null
  }

  const relsXml = entries['xl/_rels/workbook.xml.rels']
  if (!relsXml) {
    return entries['xl/worksheets/sheet1.xml'] ? 'xl/worksheets/sheet1.xml' : null
  }

  const relsDoc = new DOMParser().parseFromString(relsXml, 'application/xml')
  const relation = Array.from(relsDoc.getElementsByTagName('Relationship')).find((node) => node.getAttribute('Id') === relId)
  const target = relation?.getAttribute('Target')
  if (!target) {
    return entries['xl/worksheets/sheet1.xml'] ? 'xl/worksheets/sheet1.xml' : null
  }

  return target.startsWith('xl/') ? target : `xl/${target}`
}

function parseSheetRows(sheetXml: string): string[][] {
  const sheetDoc = new DOMParser().parseFromString(sheetXml, 'application/xml')
  const sheetData = sheetDoc.getElementsByTagName('sheetData')[0]
  if (!sheetData) {
    return []
  }

  const rowNodes = Array.from(sheetData.getElementsByTagName('row'))

  return rowNodes.map((rowNode) => {
    const cells = new Map<number, string>()
    for (const cell of Array.from(rowNode.getElementsByTagName('c'))) {
      const ref = cell.getAttribute('r') ?? ''
      const column = ref.replace(/\d+/g, '')
      if (!column) {
        continue
      }

      cells.set(columnToIndex(column), cellValue(cell))
    }

    const maxIndex = cells.size > 0 ? Math.max(...Array.from(cells.keys())) : -1
    return Array.from({ length: maxIndex + 1 }, (_, index) => cells.get(index) ?? '')
  })
}

async function parseWorkbook(file: File): Promise<ParsedTeamRow[]> {
  const entries = await readXlsxEntries(file)
  const sheetPath = resolveFirstSheetPath(entries)
  if (!sheetPath || !entries[sheetPath]) {
    throw new Error('Impossible de trouver la première feuille du classeur.')
  }

  const rows = parseSheetRows(entries[sheetPath])
  if (0 === rows.length) {
    throw new Error('Le fichier Excel est vide.')
  }

  const headers = rows[0].map((value) => value.trim())
  const headerIndexes = REQUIRED_HEADERS.map((header) => headers.indexOf(header))
  if (headerIndexes.some((index) => index < 0)) {
    throw new Error('Colonnes attendues introuvables. Le fichier doit contenir Nom, Catégorie, Numéro et Organisme.')
  }

  const parsed: ParsedTeamRow[] = []
  for (const row of rows.slice(1)) {
    const name = row[headerIndexes[0]]?.trim() ?? ''
    const category = row[headerIndexes[1]]?.trim() ?? ''
    const number = row[headerIndexes[2]]?.trim() ?? ''
    const organization = row[headerIndexes[3]]?.trim() ?? ''

    if (!name && !category && !number && !organization) {
      continue
    }

    if (!name || !category || !number || !organization) {
      throw new Error('Chaque ligne doit contenir Nom, Catégorie, Numéro et Organisme.')
    }

    parsed.push({ name, category, number, organization, duplicate: false })
  }

  return parsed
}

export default function ImportExcelDialog({ isOpen, onClose }: ImportExcelDialogProps) {
  const teams = useWizardStore((state) => state.data.teams)
  const addTeam = useWizardStore((state) => state.addTeam)
  const updateTeam = useWizardStore((state) => state.updateTeam)
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [isParsing, setIsParsing] = useState(false)
  const [isImporting, setIsImporting] = useState(false)
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [successMessage, setSuccessMessage] = useState<string | null>(null)
  const [previewRows, setPreviewRows] = useState<ParsedTeamRow[]>([])

  const existingKeys = useMemo(
    () => new Set(teams.map((team) => `${normalize(team.name)}::${normalize(team.level)}`)),
    [teams]
  )

  const resetState = useCallback(() => {
    setSelectedFile(null)
    setIsParsing(false)
    setIsImporting(false)
    setErrorMessage(null)
    setSuccessMessage(null)
    setPreviewRows([])
    if (fileInputRef.current) {
      fileInputRef.current.value = ''
    }
  }, [])

  const previewWithDuplicates = useMemo(() => {
    const seen = new Set(existingKeys)
    return previewRows.map((row) => {
      const key = `${normalize(row.name)}::${normalize(row.category)}`
      const duplicate = seen.has(key)
      seen.add(key)
      return { ...row, duplicate }
    })
  }, [existingKeys, previewRows])

  const duplicateCount = previewWithDuplicates.filter((row) => row.duplicate).length
  const importCount = previewWithDuplicates.length - duplicateCount

  const handleFile = useCallback(
    async (file: File | null) => {
      if (!file) {
        return
      }

      setSelectedFile(file)
      setErrorMessage(null)
      setSuccessMessage(null)

      if (!file.name.toLowerCase().endsWith(XLSX_EXTENSION) && file.type !== XLSX_MIME) {
        setPreviewRows([])
        setErrorMessage('Format invalide. Importez un fichier .xlsx.')
        return
      }

      setIsParsing(true)
      try {
        const rows = await parseWorkbook(file)
        setPreviewRows(rows)
      } catch (error) {
        setPreviewRows([])
        setErrorMessage(error instanceof Error ? error.message : 'Impossible de lire le fichier Excel.')
      } finally {
        setIsParsing(false)
      }
    },
    []
  )

  const handleInputChange = useCallback(
    (event: ChangeEvent<HTMLInputElement>) => {
      void handleFile(event.target.files?.[0] ?? null)
    },
    [handleFile]
  )

  const handleDrop = useCallback(
    (event: DragEvent<HTMLDivElement>) => {
      event.preventDefault()
      void handleFile(event.dataTransfer.files?.[0] ?? null)
    },
    [handleFile]
  )

  const handleClose = useCallback(() => {
    resetState()
    onClose()
  }, [onClose, resetState])

  const handleImport = useCallback(() => {
    if (previewWithDuplicates.length === 0) {
      return
    }

    setIsImporting(true)
    try {
      let created = 0
      for (const row of previewWithDuplicates) {
        if (row.duplicate) {
          continue
        }

        addTeam()
        const createdTeam = useWizardStore.getState().data.teams.at(-1)
        if (!createdTeam) {
          continue
        }

        updateTeam(createdTeam.id, {
          name: row.name,
          level: row.category,
          size: 0,
        })
        created += 1
      }

      setSuccessMessage(
        duplicateCount > 0
          ? `${created} équipe${1 === created ? '' : 's'} importée${1 === created ? '' : 's'} · ${duplicateCount} doublon${1 === duplicateCount ? '' : 's'} ignoré${1 === duplicateCount ? '' : 's'}`
          : `${created} équipe${1 === created ? '' : 's'} importée${1 === created ? '' : 's'}`
      )
      setErrorMessage(null)
      setPreviewRows([])
      setSelectedFile(null)
      if (fileInputRef.current) {
        fileInputRef.current.value = ''
      }
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : 'L’import a échoué.')
    } finally {
      setIsImporting(false)
    }
  }, [addTeam, duplicateCount, previewWithDuplicates, updateTeam])

  if (!isOpen) {
    return null
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/50" onClick={handleClose} aria-hidden="true" />

      <div className="relative z-10 w-full max-w-3xl overflow-hidden rounded-2xl border border-neutral-700 bg-neutral-800 shadow-2xl">
        <div className="flex items-start justify-between border-b border-neutral-700 px-6 py-4">
          <div>
            <h2 className="text-lg font-semibold text-white">Import Excel FFBB</h2>
            <p className="mt-1 text-sm text-neutral-400">Déposez un fichier .xlsx pour prévisualiser les équipes avant import.</p>
          </div>
          <button
            type="button"
            onClick={handleClose}
            className="rounded-md p-1 text-neutral-500 hover:bg-neutral-700 hover:text-neutral-300"
            aria-label="Fermer"
          >
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div className="space-y-5 px-6 py-5">
          <div
            onDragOver={(event) => event.preventDefault()}
            onDrop={handleDrop}
            className="rounded-xl border-2 border-dashed border-primary-700 bg-primary-900/20 p-6 text-center transition hover:border-primary-600"
          >
            <input
              ref={fileInputRef}
              type="file"
              accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
              className="hidden"
              onChange={handleInputChange}
            />

            <div className="mx-auto flex max-w-md flex-col items-center gap-3">
              <div className="flex h-12 w-12 items-center justify-center rounded-full bg-neutral-700 text-primary-400 shadow-sm">
                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.75} d="M7 16a4 4 0 01-.88-7.902A5 5 0 1116.9 6L17 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v8" />
                </svg>
              </div>

              <div>
                <p className="text-sm font-medium text-white">Glissez un fichier Excel ici</p>
                <p className="text-sm text-neutral-400">Colonnes requises : Nom, Catégorie, Numéro, Organisme</p>
              </div>

              <button
                type="button"
                onClick={() => fileInputRef.current?.click()}
                className="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700"
              >
                Choisir un fichier
              </button>

              {selectedFile && (
                <p className="text-xs text-neutral-400">Fichier sélectionné : {selectedFile.name}</p>
              )}
            </div>
          </div>

          {errorMessage && (
            <div className="rounded-lg bg-error-900/40 px-4 py-3 text-sm text-error-400" role="alert">
              {errorMessage}
            </div>
          )}

          {successMessage && (
            <div className="rounded-lg bg-success-900/40 px-4 py-3 text-sm text-success-400" role="status">
              {successMessage}
            </div>
          )}

          {isParsing && (
            <div className="rounded-lg border border-neutral-700 bg-neutral-800 px-4 py-3 text-sm text-neutral-300">
              Lecture du fichier Excel…
            </div>
          )}

          {previewWithDuplicates.length > 0 && (
            <div className="rounded-xl border border-neutral-700 bg-neutral-800">
              <div className="flex items-center justify-between border-b border-neutral-700 px-4 py-3">
                <div>
                  <h3 className="text-sm font-semibold text-white">Prévisualisation</h3>
                  <p className="text-xs text-neutral-400">
                    {previewWithDuplicates.length} équipe{previewWithDuplicates.length > 1 ? 's' : ''} détectée
                    {previewWithDuplicates.length > 1 ? 's' : ''}
                  </p>
                </div>
                {duplicateCount > 0 && (
                  <span className="rounded-full bg-warning-900/40 px-2.5 py-1 text-xs font-medium text-warning-300">
                    {duplicateCount} doublon{duplicateCount > 1 ? 's' : ''} détecté{duplicateCount > 1 ? 's' : ''}
                  </span>
                )}
              </div>

              <div className="max-h-80 overflow-auto">
                <table className="min-w-full divide-y divide-neutral-700 text-left text-sm">
                  <thead className="sticky top-0 bg-neutral-800 text-xs uppercase tracking-wide text-neutral-400">
                    <tr>
                      <th className="px-4 py-3 font-medium">Nom</th>
                      <th className="px-4 py-3 font-medium">Catégorie</th>
                      <th className="px-4 py-3 font-medium">Statut</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-neutral-700 bg-neutral-800">
                    {previewWithDuplicates.map((row) => (
                      <tr key={`${row.number}-${row.name}-${row.category}`}>
                        <td className="px-4 py-3 font-medium text-white">{row.name}</td>
                        <td className="px-4 py-3 text-neutral-300">{row.category}</td>
                        <td className="px-4 py-3">
                          {row.duplicate ? (
                            <span className="rounded-full bg-warning-900/40 px-2.5 py-1 text-xs font-medium text-warning-300">
                              Doublon — ignorée
                            </span>
                          ) : (
                            <span className="rounded-full bg-success-900/40 px-2.5 py-1 text-xs font-medium text-success-300">
                              Nouvelle équipe
                            </span>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          <div className="flex items-center justify-between border-t border-neutral-700 pt-4">
            <p className="text-sm text-neutral-400">
              {previewWithDuplicates.length > 0
                ? `${importCount} équipe${1 === importCount ? '' : 's'} importable${1 === importCount ? '' : 's'}`
                : 'Aucun fichier importé'}
            </p>

            <div className="flex items-center gap-3">
              <button
                type="button"
                onClick={handleClose}
                className="rounded-lg border border-neutral-600 bg-neutral-700 px-4 py-2 text-sm font-medium text-neutral-200 hover:bg-neutral-600"
              >
                Annuler
              </button>
              <button
                type="button"
                onClick={handleImport}
                disabled={isParsing || isImporting || previewWithDuplicates.length === 0}
                className="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {isImporting ? 'Import en cours…' : 'Confirmer l’import'}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
