import { test, expect, type Page } from '@playwright/test'

const TEST_USER = {
  email: 'test2@example.com',
  password: 'SecurePass123!',
}

const EVIDENCE_DIR = '../../.omo/evidence/smoke-test'

async function takeScreenshot(page: Page, name: string) {
  await page.screenshot({ path: `${EVIDENCE_DIR}/${name}.png`, fullPage: true })
}

async function apiLogin(request: import('@playwright/test').APIRequestContext) {
  const loginRes = await request.post('/api/login', {
    data: {
      email: TEST_USER.email,
      password: TEST_USER.password,
    },
  })

  if (!loginRes.ok()) {
    const body = await loginRes.text().catch(() => '')
    throw new Error(`API login failed: ${loginRes.status()} ${body}`)
  }

  const { token } = await loginRes.json() as { token: string }

  const meRes = await request.get('/api/me', {
    headers: { Authorization: `Bearer ${token}` },
  })

  if (!meRes.ok()) {
    throw new Error(`API /me failed: ${meRes.status()}`)
  }

  const me = await meRes.json() as {
    id: string
    email: string
    firstName: string
    lastName: string
    club?: { id: string; name: string }
    hasGenerated?: boolean
  }

  if (!me.club) {
    throw new Error('API /me returned no club — user is not properly set up')
  }

  return {
    token,
    user: { id: me.id, email: me.email, roles: ['ROLE_USER'] },
    club: { id: me.club.id, name: me.club.name, slug: me.club.id },
    hasGenerated: me.hasGenerated ?? false,
  }
}

async function login(page: Page, request: import('@playwright/test').APIRequestContext) {
  const auth = await apiLogin(request)

  await page.goto('/')
  await page.evaluate((authState) => {
    localStorage.setItem(
      'auth-storage',
      JSON.stringify({
        state: {
          token: authState.token,
          user: authState.user,
          club: authState.club,
          hasGenerated: authState.hasGenerated,
          isAuthenticated: true,
          isAuthInitialized: true,
        },
        version: 0,
      })
    )
  }, auth)

  await page.reload()
  await page.waitForURL('/', { timeout: 10000 })
}

async function fillVenueStep(page: Page) {
  await page.fill('input[placeholder="Ex: Gymnase Principal"]', 'Gymnase Principal')

  await page.click('text=Details')
  await page.waitForTimeout(300)

  const mondaySection = page.locator('h5:has-text("Lun")').locator('..')
  await mondaySection.locator('button:has-text("+ Plage")').click()
  await page.waitForTimeout(200)

  const wednesdaySection = page.locator('h5:has-text("Mer")').locator('..')
  await wednesdaySection.locator('button:has-text("+ Plage")').click()
  await page.waitForTimeout(200)

  await page.waitForTimeout(800)
}

async function fillVenueConstraintStep(page: Page) {
  await page.click('button:has-text("+ Ajouter une contrainte")')
  await page.waitForTimeout(200)

  const venueSelect = page.locator('select').first()
  await venueSelect.selectOption({ label: 'Gymnase Principal' })
  await page.waitForTimeout(500)
}

async function fillTeamStep(page: Page) {
  await page.click('button:has-text("+ Ajouter une equipe")')
  await page.waitForTimeout(200)

  await page.fill('input[placeholder="Ex: U15 Elite"]', 'U15 Elite')

  const categorySelect = page.locator('select').nth(0)
  const options = await categorySelect.locator('option').allTextContents()
  if (options.length > 1) {
    await categorySelect.selectOption({ index: 1 })
  }

  const genderSelect = page.locator('select').nth(1)
  await genderSelect.selectOption('M')

  await page.fill('input[type="number"]', '12')

  await page.click('text=Details')
  await page.waitForTimeout(200)
  const sessionsInput = page.locator('input[type="number"]').last()
  await sessionsInput.fill('2')

  await page.waitForTimeout(800)
}

async function fillTeamConstraintStep(page: Page) {
  await page.click('button:has-text("+ Ajouter")')
  await page.waitForTimeout(200)

  const teamSelect = page.locator('select').nth(0)
  await teamSelect.selectOption({ label: 'U15 Elite' })

  const typeSelect = page.locator('select').nth(1)
  await typeSelect.selectOption('preferred')

  const venueSelect = page.locator('select').nth(3)
  await venueSelect.selectOption({ label: 'Gymnase Principal' })

  await page.waitForTimeout(500)
}

async function fillCoachStep(page: Page) {
  await page.click('button:has-text("+ Ajouter un coach")')
  await page.waitForTimeout(200)

  await page.fill('input[placeholder="Nom du coach"]', 'Coach Jean')

  await page.click('text=Details')
  await page.waitForTimeout(200)

  await page.click('button:has-text("U15 Elite")')
  await page.waitForTimeout(500)
}

async function fillCoachConstraintStep(page: Page) {
  await page.click('button:has-text("+ Ajouter")')
  await page.waitForTimeout(200)

  const coachSelect = page.locator('select').nth(0)
  await coachSelect.selectOption({ label: 'Coach Jean' })

  const venueSelect = page.locator('select').nth(2)
  await venueSelect.selectOption({ label: 'Gymnase Principal' })

  await page.waitForTimeout(500)
}

async function fillTierListStep(page: Page) {
  const teamCard = page.locator('.cursor-grab:has-text("U15 Elite")')
  const tierADropZone = page.locator('[id="tier-A"]')

  const cardBox = await teamCard.boundingBox()
  const dropBox = await tierADropZone.boundingBox()

  if (cardBox && dropBox) {
    await page.mouse.move(cardBox.x + cardBox.width / 2, cardBox.y + cardBox.height / 2)
    await page.mouse.down()
    await page.mouse.move(dropBox.x + dropBox.width / 2, dropBox.y + dropBox.height / 2, { steps: 10 })
    await page.mouse.up()
  }

  await page.waitForTimeout(500)
}

async function fillValidationStep(page: Page) {
  await page.waitForTimeout(500)
}

async function fillSummaryStep(page: Page) {
  await page.waitForSelector('text=Resume et Generation')
  await page.waitForTimeout(500)
}

test.describe('Smoke Test: Core User Flow', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/')
    await page.evaluate(() => {
      localStorage.clear()
      sessionStorage.clear()
    })
  })

  test('login → wizard → generation → dashboard', async ({ page, request }) => {
    test.setTimeout(120000)

    // ── 1. Login ──
    await login(page, request)
    await takeScreenshot(page, '01-login-success')

    // ── 2. Navigate to wizard ──
    await page.goto('/wizard')
    await page.waitForSelector('text=Assistant de configuration')
    await takeScreenshot(page, '02-wizard-start')

    // ── Step 0: Venues ──
    await fillVenueStep(page)
    await takeScreenshot(page, '03-step-0-venues')
    await page.click('button:has-text("Suivant")')
    await page.waitForTimeout(500)

    // ── Step 1: Venue Constraints ──
    await fillVenueConstraintStep(page)
    await takeScreenshot(page, '04-step-1-venue-constraints')
    await page.click('button:has-text("Suivant")')
    await page.waitForTimeout(500)

    // ── Step 2: Teams ──
    await fillTeamStep(page)
    await takeScreenshot(page, '05-step-2-teams')
    await page.click('button:has-text("Suivant")')
    await page.waitForTimeout(500)

    // ── Step 3: Team Constraints ──
    await fillTeamConstraintStep(page)
    await takeScreenshot(page, '06-step-3-team-constraints')
    await page.click('button:has-text("Suivant")')
    await page.waitForTimeout(500)

    // ── Step 4: Coaches ──
    await fillCoachStep(page)
    await takeScreenshot(page, '07-step-4-coaches')
    await page.click('button:has-text("Suivant")')
    await page.waitForTimeout(500)

    // ── Step 5: Coach Constraints ──
    await fillCoachConstraintStep(page)
    await takeScreenshot(page, '08-step-5-coach-constraints')
    await page.click('button:has-text("Suivant")')
    await page.waitForTimeout(500)

    // ── Step 6: Tier List ──
    await fillTierListStep(page)
    await takeScreenshot(page, '09-step-6-tier-list')
    await page.click('button:has-text("Suivant")')
    await page.waitForTimeout(500)

    // ── Step 7: Validation ──
    await fillValidationStep(page)
    await takeScreenshot(page, '10-step-7-validation')
    await page.click('button:has-text("Suivant")')
    await page.waitForTimeout(500)

    // ── Step 8: Summary & Generate ──
    await fillSummaryStep(page)
    await takeScreenshot(page, '11-step-8-summary')

    // Click generate — opens preview modal
    await page.click('button:has-text("Generer le planning")')
    await page.waitForTimeout(800)
    await takeScreenshot(page, '12-generate-preview-modal')

    // Confirm generation in modal
    await page.click('button:has-text("Lancer la generation")')
    await page.waitForTimeout(1000)
    await takeScreenshot(page, '13-generating')

    // ── 3. Wait for redirect to dashboard ──
    await page.waitForURL('/dashboard', { timeout: 60000 })
    await takeScreenshot(page, '14-dashboard-loaded')

    // ── 4. Verify FullCalendar is rendered ──
    const calendar = page.locator('.fc-view-harness')
    await expect(calendar).toBeVisible({ timeout: 15000 })
    await takeScreenshot(page, '15-calendar-visible')

    // ── 5. Verify calendar shows events (slots) ──
    // Wait a bit for slots to load via React Query
    await page.waitForTimeout(3000)

    const events = page.locator('.fc-event')
    const eventCount = await events.count()
    expect(eventCount).toBeGreaterThan(0)

    await takeScreenshot(page, '16-dashboard-with-events')
  })
})
