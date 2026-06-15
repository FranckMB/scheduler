import { test, expect, type Page } from '@playwright/test'

const TEST_USER = {
  email: 'test2@example.com',
  password: 'SecurePass123!',
}

const EVIDENCE_DIR = '../../.omo/evidence/task-14-e2e'

async function takeScreenshot(page: Page, name: string) {
  await page.screenshot({ path: `${EVIDENCE_DIR}/${name}.png`, fullPage: true })
}

async function login(page: Page) {
  await page.goto('/login')
  await page.waitForSelector('form')
  await page.fill('#email', TEST_USER.email)
  await page.fill('#password', TEST_USER.password)
  await page.click('button[type="submit"]')
  await page.waitForURL('/', { timeout: 10000 })
}

async function fillVenueStep(page: Page) {
  // The wizard starts with one empty venue
  await page.fill('input[placeholder="Ex: Gymnase Principal"]', 'Gymnase Principal')

  // Expand details to add availability ranges
  await page.click('text=Details')
  await page.waitForTimeout(300)

  // Add a range for Monday
  const mondaySection = page.locator('h5:has-text("Lun")').locator('..')
  await mondaySection.locator('button:has-text("+ Plage")').click()
  await page.waitForTimeout(200)

  // Add a range for Wednesday
  const wednesdaySection = page.locator('h5:has-text("Mer")').locator('..')
  await wednesdaySection.locator('button:has-text("+ Plage")').click()
  await page.waitForTimeout(200)

  // Wait for auto-save debounce
  await page.waitForTimeout(800)
}

async function fillVenueConstraintStep(page: Page) {
  await page.click('button:has-text("+ Ajouter une contrainte")')
  await page.waitForTimeout(200)

  // Select venue
  const venueSelect = page.locator('select').first()
  await venueSelect.selectOption({ label: 'Gymnase Principal' })

  // Type is already gender_restriction, value is already M
  await page.waitForTimeout(500)
}

async function fillTeamStep(page: Page) {
  await page.click('button:has-text("+ Ajouter une equipe")')
  await page.waitForTimeout(200)

  // Fill team name
  await page.fill('input[placeholder="Ex: U15 Elite"]', 'U15 Elite')

  // Select sport category (first available)
  const categorySelect = page.locator('select').nth(0)
  const options = await categorySelect.locator('option').allTextContents()
  if (options.length > 1) {
    await categorySelect.selectOption({ index: 1 })
  }

  // Select gender
  const genderSelect = page.locator('select').nth(1)
  await genderSelect.selectOption('M')

  // Fill size
  await page.fill('input[type="number"]', '12')

  // Expand details and set sessions count
  await page.click('text=Details')
  await page.waitForTimeout(200)
  const sessionsInput = page.locator('input[type="number"]').last()
  await sessionsInput.fill('2')

  await page.waitForTimeout(800)
}

async function fillTeamConstraintStep(page: Page) {
  await page.click('button:has-text("+ Ajouter")')
  await page.waitForTimeout(200)

  // Select team
  const teamSelect = page.locator('select').nth(0)
  await teamSelect.selectOption({ label: 'U15 Elite' })

  // Select type
  const typeSelect = page.locator('select').nth(1)
  await typeSelect.selectOption('preferred')

  // Select venue
  const venueSelect = page.locator('select').nth(2)
  await venueSelect.selectOption({ label: 'Gymnase Principal' })

  await page.waitForTimeout(500)
}

async function fillCoachStep(page: Page) {
  await page.click('button:has-text("+ Ajouter un coach")')
  await page.waitForTimeout(200)

  // Fill coach name
  await page.fill('input[placeholder="Nom du coach"]', 'Coach Jean')

  // Expand details and assign to team
  await page.click('text=Details')
  await page.waitForTimeout(200)

  // Click team assignment button
  await page.click('button:has-text("U15 Elite")')
  await page.waitForTimeout(500)
}

async function fillCoachConstraintStep(page: Page) {
  await page.click('button:has-text("+ Ajouter")')
  await page.waitForTimeout(200)

  // Select coach
  const coachSelect = page.locator('select').nth(0)
  await coachSelect.selectOption({ label: 'Coach Jean' })

  // Select venue preference
  const venueSelect = page.locator('select').nth(1)
  await venueSelect.selectOption({ label: 'Gymnase Principal' })

  await page.waitForTimeout(500)
}

async function fillTierListStep(page: Page) {
  // The team should be in tier C by default
  // Drag it to tier A
  const teamCard = page.locator('.cursor-grab:has-text("U15 Elite")')
  const tierADropZone = page.locator('[id="tier-A"]')

  await teamCard.dragTo(tierADropZone)
  await page.waitForTimeout(500)
}

async function fillValidationStep(page: Page) {
  // Just a review step, no interaction needed
  await page.waitForTimeout(500)
}

async function fillSummaryStep(page: Page) {
  // Wait for summary to render
  await page.waitForSelector('text=Resume et Generation')
  await page.waitForTimeout(500)
}

test.describe('Wizard Flow E2E', () => {
  test.beforeEach(async ({ page }) => {
    // Clear persisted wizard state and auth
    await page.goto('/')
    await page.evaluate(() => {
      localStorage.clear()
      sessionStorage.clear()
    })
  })

  test('full wizard flow with schedule generation', async ({ page }) => {
    // ── 1. Login ──
    await login(page)
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

    // Click generate
    await page.click('button:has-text("Generer le planning")')
    await page.waitForTimeout(1000)
    await takeScreenshot(page, '12-generating-schedule')

    // Wait for navigation to schedule view
    await page.waitForURL(/\/schedules\//, { timeout: 30000 })
    await page.waitForSelector('.fc-view-harness', { timeout: 15000 })
    await takeScreenshot(page, '13-schedule-view')

    // Verify schedule appears
    const calendar = page.locator('.fc-view-harness')
    await expect(calendar).toBeVisible()

    // Verify schedule header shows status
    const statusText = page.locator('text=/Status:/')
    await expect(statusText).toBeVisible()

    // Verify there are calendar events (slots)
    const events = page.locator('.fc-event')
    const eventCount = await events.count()
    expect(eventCount).toBeGreaterThan(0)

    await takeScreenshot(page, '14-schedule-with-events')
  })
})
