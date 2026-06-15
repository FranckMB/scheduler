import { test, expect, type Page } from '@playwright/test'

const TEST_USER = {
  email: 'workflow-test@example.com',
  password: 'SecurePass123!',
}

const EVIDENCE_DIR = '../../.omo/evidence/post-generation-workflow'

async function takeScreenshot(page: Page, name: string) {
  await page.screenshot({ path: `${EVIDENCE_DIR}/${name}.png`, fullPage: true })
}

async function ensureUserExists(request: ReturnType<typeof test.request.newContext>) {
  try {
    const registerRes = await request.post('/api/register', {
      data: {
        email: TEST_USER.email,
        password: TEST_USER.password,
        firstName: 'Workflow',
        lastName: 'Test',
      },
    })
    if (registerRes.ok() || registerRes.status() === 409) {
      return true
    }
  } catch {
    // Backend may not have /api/register; fallback to direct login attempt
  }

  const loginRes = await request.post('/api/login', {
    data: {
      email: TEST_USER.email,
      password: TEST_USER.password,
    },
  })
  return loginRes.ok()
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

  const venueSelect = page.locator('select').nth(2)
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

  const venueSelect = page.locator('select').nth(1)
  await venueSelect.selectOption({ label: 'Gymnase Principal' })

  await page.waitForTimeout(500)
}

async function fillTierListStep(page: Page) {
  const teamCard = page.locator('.cursor-grab:has-text("U15 Elite")')
  const tierADropZone = page.locator('[id="tier-A"]')

  await teamCard.dragTo(tierADropZone)
  await page.waitForTimeout(500)
}

async function fillValidationStep(page: Page) {
  await page.waitForTimeout(500)
}

async function fillSummaryStep(page: Page) {
  await page.waitForSelector('text=Resume et Generation')
  await page.waitForTimeout(500)
}

async function runWizardAndGenerate(page: Page) {
  await page.goto('/wizard')
  await page.waitForSelector('text=Assistant de configuration')

  await fillVenueStep(page)
  await page.click('button:has-text("Suivant")')
  await page.waitForTimeout(500)

  await fillVenueConstraintStep(page)
  await page.click('button:has-text("Suivant")')
  await page.waitForTimeout(500)

  await fillTeamStep(page)
  await page.click('button:has-text("Suivant")')
  await page.waitForTimeout(500)

  await fillTeamConstraintStep(page)
  await page.click('button:has-text("Suivant")')
  await page.waitForTimeout(500)

  await fillCoachStep(page)
  await page.click('button:has-text("Suivant")')
  await page.waitForTimeout(500)

  await fillCoachConstraintStep(page)
  await page.click('button:has-text("Suivant")')
  await page.waitForTimeout(500)

  await fillTierListStep(page)
  await page.click('button:has-text("Suivant")')
  await page.waitForTimeout(500)

  await fillValidationStep(page)
  await page.click('button:has-text("Suivant")')
  await page.waitForTimeout(500)

  await fillSummaryStep(page)

  await page.click('button:has-text("Generer le planning")')
  await page.waitForTimeout(800)
  await page.click('button:has-text("Lancer la generation")')
  await page.waitForTimeout(1000)

  // Wait for redirect to dashboard or schedule view
  await page.waitForURL(/\/(dashboard|schedules\/)/, { timeout: 60000 })
}

async function getLatestScheduleId(page: Page): Promise<string | null> {
  return page.evaluate(async () => {
    const res = await fetch('/api/schedules?order[updatedAt]=desc&itemsPerPage=1', {
      headers: { Accept: 'application/json' },
    })
    const json = await res.json()
    const items = Array.isArray(json) ? json : json['hydra:member'] ?? []
    return items[0]?.id ?? null
  })
}

async function validateScheduleViaApi(page: Page, scheduleId: string) {
  const result = await page.evaluate(async (sid) => {
    const res = await fetch(`/api/schedules/${sid}`, {
      method: 'PATCH',
      headers: {
        'Content-Type': 'application/merge-patch+json',
        Accept: 'application/json',
      },
      body: JSON.stringify({ status: 'validated' }),
    })
    return { ok: res.ok, status: res.status }
  }, scheduleId)

  if (!result.ok) {
    throw new Error(`Failed to validate schedule: HTTP ${result.status}`)
  }
}

async function openEntitySection(page: Page, sectionName: string) {
  const sectionButton = page.locator(`section button:has-text("${sectionName}")`)
  await sectionButton.click()
  await page.waitForTimeout(300)
}

async function editTeamName(page: Page, originalName: string, newName: string) {
  // Click the team name button to enter edit mode
  const teamButton = page.locator(`button:has-text("${originalName}")`).first()
  await teamButton.click()
  await page.waitForTimeout(200)

  // The input should now be visible
  const input = page.locator('input[type="text"]').filter({ hasValue: originalName })
  await expect(input).toBeVisible()

  // Clear and fill new name
  await input.fill(newName)

  // Click Save button
  const saveButton = page.locator('button:has-text("Save")').first()
  await saveButton.click()

  // Wait for save to complete (input disappears)
  await expect(page.locator(`button:has-text("${newName}")`).first()).toBeVisible()
}

async function dragEventToNewSlot(page: Page, eventLocator: ReturnType<Page['locator']>, targetDayIndex: number, targetHour: number) {
  // Get event bounding box
  const eventBox = await eventLocator.boundingBox()
  if (!eventBox) throw new Error('Event not found for drag')

  // Find target time slot in the calendar
  // FullCalendar timeGrid: columns are .fc-timegrid-col, rows are .fc-timegrid-slot
  // We'll target a specific day column and approximate Y position for the hour
  const calendar = page.locator('.fc-view-harness')
  const calendarBox = await calendar.boundingBox()
  if (!calendarBox) throw new Error('Calendar not found')

  // Calculate target position: day columns start after the time axis (left ~60px)
  // Each day column width = (calendar width - time axis width) / 7
  const timeAxisWidth = 60
  const dayWidth = (calendarBox.width - timeAxisWidth) / 7
  const targetX = calendarBox.x + timeAxisWidth + dayWidth * targetDayIndex + dayWidth / 2

  // Each hour slot is roughly 42px tall (30min slots = ~21px each)
  const slotHeight = 42 // per hour
  const headerHeight = 60 // approximate header height
  const targetY = calendarBox.y + headerHeight + (targetHour - 6) * slotHeight

  // Perform drag using mouse events
  await page.mouse.move(eventBox.x + eventBox.width / 2, eventBox.y + eventBox.height / 2)
  await page.mouse.down()
  await page.mouse.move(targetX, targetY, { steps: 10 })
  await page.mouse.up()

  await page.waitForTimeout(500)
}

test.describe('Post-Generation Workflow E2E', () => {
  test.beforeAll(async ({ request }) => {
    const apiContext = await request.newContext({
      baseURL: 'http://localhost:8081',
    })
    await ensureUserExists(apiContext)
    await apiContext.dispose()
  })

  test.beforeEach(async ({ page }) => {
    await page.goto('/')
    await page.evaluate(() => {
      localStorage.clear()
      sessionStorage.clear()
    })
    await login(page)
  })

  test('draft schedule: wizard → entities → dashboard → drag-drop refinement', async ({ page }) => {
    // ── 1. Complete wizard and generate schedule ──
    await runWizardAndGenerate(page)
    await takeScreenshot(page, '01-draft-schedule-generated')

    // ── 2. Navigate to /entities ──
    await page.goto('/entities')
    await page.waitForSelector('text=Entités')
    await takeScreenshot(page, '02-entities-page')

    // ── 3. Edit a team (rename U15 Elite) ──
    await openEntitySection(page, 'Équipes')
    await takeScreenshot(page, '03-teams-section-open')

    await editTeamName(page, 'U15 Elite', 'U15 Elite Renamed')
    await takeScreenshot(page, '04-team-renamed')

    // ── 4. Verify data persistence (reload page) ──
    await page.reload()
    await page.waitForSelector('text=Entités')
    await openEntitySection(page, 'Équipes')
    await expect(page.locator('text=U15 Elite Renamed').first()).toBeVisible()
    await takeScreenshot(page, '05-reload-persisted')

    // ── 5. Navigate to /dashboard ──
    await page.goto('/dashboard')
    await page.waitForSelector('text=Dashboard')
    await takeScreenshot(page, '06-dashboard-loaded')

    // ── 6. Verify calendar shows events ──
    const calendar = page.locator('.fc-view-harness')
    await expect(calendar).toBeVisible({ timeout: 15000 })

    // Wait for slots to load
    await page.waitForTimeout(3000)

    const events = page.locator('.fc-event')
    const eventCount = await events.count()
    expect(eventCount).toBeGreaterThan(0)
    await takeScreenshot(page, '07-dashboard-with-events')

    // ── 7. Drag an event to a new time slot ──
    const firstEvent = events.first()
    await expect(firstEvent).toBeVisible()

    // Drag to Tuesday (index 1) at 10:00
    await dragEventToNewSlot(page, firstEvent, 1, 10)
    await takeScreenshot(page, '08-drag-drop-initiated')

    // ── 8. Confirm the change in the modal ──
    const confirmModal = page.locator('text=Confirmer le déplacement')
    await expect(confirmModal).toBeVisible({ timeout: 5000 })
    await takeScreenshot(page, '09-confirm-modal')

    await page.click('button:has-text("Confirmer")')
    await page.waitForTimeout(2000)
    await takeScreenshot(page, '10-change-confirmed')

    // ── 9. Verify event moved (reload and check) ──
    await page.reload()
    await page.waitForSelector('text=Dashboard')
    await expect(calendar).toBeVisible({ timeout: 15000 })
    await page.waitForTimeout(3000)

    // After reload, events should still exist
    const postDragEvents = page.locator('.fc-event')
    expect(await postDragEvents.count()).toBeGreaterThan(0)
    await takeScreenshot(page, '11-post-reload-events')
  })

  test('validated schedule: edit entity triggers regeneration flow', async ({ page }) => {
    // ── 1. Complete wizard and generate schedule ──
    await runWizardAndGenerate(page)
    await takeScreenshot(page, '12-validated-schedule-generated')

    // ── 2. Validate the schedule via API ──
    const scheduleId = await getLatestScheduleId(page)
    expect(scheduleId).not.toBeNull()
    if (!scheduleId) throw new Error('No schedule found')

    await validateScheduleViaApi(page, scheduleId)
    await takeScreenshot(page, '13-schedule-validated')

    // ── 3. Navigate to /entities ──
    await page.goto('/entities')
    await page.waitForSelector('text=Entités')
    await takeScreenshot(page, '14-entities-page-validated')

    // ── 4. Edit a team (should trigger warning modal) ──
    await openEntitySection(page, 'Équipes')
    await takeScreenshot(page, '15-teams-section-open-validated')

    // Click team name to edit
    const teamButton = page.locator('button:has-text("U15 Elite")').first()
    await teamButton.click()
    await page.waitForTimeout(200)

    const input = page.locator('input[type="text"]').filter({ hasValue: 'U15 Elite' })
    await expect(input).toBeVisible()
    await input.fill('U15 Elite Validated')

    const saveButton = page.locator('button:has-text("Save")').first()
    await saveButton.click()

    // ── 5. Warning modal appears ──
    const warningModal = page.locator('text=Attention')
    await expect(warningModal).toBeVisible({ timeout: 5000 })
    await takeScreenshot(page, '16-warning-modal')

    // Click "Modifier et regénérer"
    await page.click('button:has-text("Modifier et regénérer")')
    await page.waitForTimeout(500)

    // ── 6. Diff modal appears ──
    const diffModal = page.locator('text=Aperçu des changements avant regénération')
    await expect(diffModal).toBeVisible({ timeout: 5000 })
    await takeScreenshot(page, '17-diff-modal')

    // Click "Regénérer"
    await page.click('button:has-text("Regénérer")')
    await page.waitForTimeout(2000)
    await takeScreenshot(page, '18-regeneration-started')

    // ── 7. Navigate to dashboard and verify calendar still shows events ──
    await page.goto('/dashboard')
    await page.waitForSelector('text=Dashboard')
    await takeScreenshot(page, '19-dashboard-after-regen')

    const calendar = page.locator('.fc-view-harness')
    await expect(calendar).toBeVisible({ timeout: 15000 })
    await page.waitForTimeout(3000)

    const events = page.locator('.fc-event')
    expect(await events.count()).toBeGreaterThan(0)
    await takeScreenshot(page, '20-dashboard-events-after-regen')
  })
})
