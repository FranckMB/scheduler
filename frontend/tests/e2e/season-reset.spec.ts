import { test, expect, type Page, type APIRequestContext } from '@playwright/test'

const TEST_USER = {
  email: 'test2@example.com',
  password: 'SecurePass123!',
}

const CONFIRMATION_TEXT = 'je veux réinitialiser ma saison'
const EVIDENCE_DIR = '../../.omo/evidence/task-reset-e2e'

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

async function getAuthState(page: Page): Promise<{ token: string | null; clubId: string | null; seasonId: string | null }> {
  return page.evaluate(() => {
    const raw = localStorage.getItem('auth-storage')
    if (!raw) return { token: null, clubId: null, seasonId: null }
    try {
      const parsed = JSON.parse(raw)
      return {
        token: parsed.state?.token ?? null,
        clubId: parsed.state?.club?.id ?? null,
        seasonId: parsed.state?.seasonId ?? null,
      }
    } catch {
      return { token: null, clubId: null, seasonId: null }
    }
  })
}

async function setSeasonId(page: Page, seasonId: string) {
  await page.evaluate((id) => {
    const raw = localStorage.getItem('auth-storage')
    if (!raw) return
    try {
      const parsed = JSON.parse(raw)
      if (parsed.state) {
        parsed.state.seasonId = id
        localStorage.setItem('auth-storage', JSON.stringify(parsed))
      }
    } catch (error) {
      void error
    }
  }, seasonId)
}

async function apiGet(request: APIRequestContext, token: string, endpoint: string, clubId?: string, seasonId?: string) {
  const headers: Record<string, string> = {
    Authorization: `Bearer ${token}`,
    Accept: 'application/ld+json',
  }
  if (clubId) {
    headers['X-Club-Id'] = clubId
  }
  if (seasonId) {
    headers['X-Season-Id'] = seasonId
  }
  return request.get(`http://localhost:8080/api/${endpoint}`, { headers })
}

async function seedTestData(request: APIRequestContext, token: string, clubId: string, seasonId: string) {
  const venueResponse = await request.post('http://localhost:8080/api/venues', {
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/ld+json', 'X-Club-Id': clubId, 'X-Season-Id': seasonId },
    data: JSON.stringify({ name: 'Gymnase Test', source: 'manual' }),
  })
  expect(venueResponse.status()).toBe(201)

  const teamResponse = await request.post('http://localhost:8080/api/teams', {
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/ld+json', 'X-Club-Id': clubId, 'X-Season-Id': seasonId },
    data: JSON.stringify({ name: 'U15 Test', gender: 'M' }),
  })
  expect(teamResponse.status()).toBe(201)

  const coachResponse = await request.post('http://localhost:8080/api/coaches', {
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/ld+json', 'X-Club-Id': clubId, 'X-Season-Id': seasonId },
    data: JSON.stringify({ firstName: 'Jean', lastName: 'Test' }),
  })
  expect(coachResponse.status()).toBe(201)

  const scheduleResponse = await request.post('http://localhost:8080/api/schedules', {
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/ld+json', 'X-Club-Id': clubId, 'X-Season-Id': seasonId },
    data: JSON.stringify({ name: 'Planning Test', status: 'draft' }),
  })
  expect(scheduleResponse.status()).toBe(201)
}

async function verifyDataExists(request: APIRequestContext, token: string, clubId: string, seasonId: string) {
  const schedulesResponse = await apiGet(request, token, 'schedules', clubId, seasonId)
  expect(schedulesResponse.status()).toBe(200)
  const schedulesData = await schedulesResponse.json()
  const scheduleCount = schedulesData['hydra:totalItems'] ?? schedulesData.totalItems ?? schedulesData['member']?.length ?? 0
  if (scheduleCount === 0) {
    await seedTestData(request, token, clubId, seasonId)
  }
}

test.describe('Season Reset E2E', () => {
  test.beforeEach(async ({ page }) => {
    // Clear persisted wizard state and auth
    await page.goto('/')
    await page.evaluate(() => {
      localStorage.clear()
      sessionStorage.clear()
    })
  })

  test('full season reset flow with DB verification', async ({ page, request }) => {
    await login(page)
    await takeScreenshot(page, '01-login-success')

    const authState = await getAuthState(page)
    expect(authState.token).not.toBeNull()
    expect(authState.clubId).not.toBeNull()

    let seasonId = authState.seasonId
    if (!seasonId) {
      const seasonsResponse = await apiGet(request, authState.token!, 'seasons', authState.clubId!)
      expect(seasonsResponse.status()).toBe(200)
      const seasonsData = await seasonsResponse.json()
      const seasons = seasonsData['hydra:member'] ?? seasonsData.member ?? []
      expect(seasons.length).toBeGreaterThan(0)
      seasonId = seasons[0].id
      await setSeasonId(page, seasonId)
    }

    await verifyDataExists(request, authState.token!, authState.clubId!, seasonId)
    await takeScreenshot(page, '02-schedule-exists')

    await page.goto('/profile')
    await page.waitForSelector('text=Profil')
    await takeScreenshot(page, '03-profile-page')

    const dangerZoneHeading = page.locator('h2:has-text("Zone de danger")')
    await expect(dangerZoneHeading).toBeVisible()

    const resetButton = page.locator('button:has-text("Réinitialiser ma saison")')
    await expect(resetButton).toBeVisible()
    await resetButton.click()

    await page.waitForSelector('text=Cette action est irréversible')
    await takeScreenshot(page, '04-modal-open')

    const riskList = page.locator('text=Toutes les données de la saison seront supprimées')
    await expect(riskList).toBeVisible()

    const confirmInput = page.locator('input#confirm-reset')
    await confirmInput.fill('wrong text')
    await page.waitForTimeout(200)

    const modalResetButton = page.locator('button:has-text("Réinitialiser"):not(:has-text("ma saison"))')
    await expect(modalResetButton).toBeDisabled()
    await takeScreenshot(page, '05-wrong-text-disabled')

    await confirmInput.fill('')
    await confirmInput.fill(CONFIRMATION_TEXT)
    await page.waitForTimeout(200)

    await expect(modalResetButton).toBeEnabled()
    await takeScreenshot(page, '06-correct-text-enabled')

    await modalResetButton.click()

    await page.waitForURL('/wizard', { timeout: 15000 })
    await takeScreenshot(page, '07-redirected-to-wizard')

    const sidebar = page.locator('aside nav')
    await expect(sidebar).toBeVisible()

    const wizardLink = sidebar.locator('a:has-text("Wizard")')
    await expect(wizardLink).toBeVisible()

    const planningLink = sidebar.locator('a:has-text("Planning")')
    await expect(planningLink).toHaveCount(0)

    const entitiesLink = sidebar.locator('a:has-text("Entités")')
    await expect(entitiesLink).toHaveCount(0)

    await takeScreenshot(page, '08-sidebar-wizard-only')

    const clubId = authState.clubId!

    const schedulesResponse = await apiGet(request, authState.token!, 'schedules', clubId, seasonId)
    expect(schedulesResponse.status()).toBe(200)
    const schedulesData = await schedulesResponse.json()
    const scheduleCount = schedulesData['hydra:totalItems'] ?? schedulesData.totalItems ?? schedulesData['member']?.length ?? 0
    expect(scheduleCount).toBe(0)

    const teamsResponse = await apiGet(request, authState.token!, 'teams', clubId, seasonId)
    expect(teamsResponse.status()).toBe(200)
    const teamsData = await teamsResponse.json()
    const teamCount = teamsData['hydra:totalItems'] ?? teamsData.totalItems ?? teamsData['member']?.length ?? 0
    expect(teamCount).toBe(0)

    const venuesResponse = await apiGet(request, authState.token!, 'venues', clubId, seasonId)
    expect(venuesResponse.status()).toBe(200)
    const venuesData = await venuesResponse.json()
    const venueCount = venuesData['hydra:totalItems'] ?? venuesData.totalItems ?? venuesData['member']?.length ?? 0
    expect(venueCount).toBe(0)

    const coachesResponse = await apiGet(request, authState.token!, 'coaches', clubId, seasonId)
    expect(coachesResponse.status()).toBe(200)
    const coachesData = await coachesResponse.json()
    const coachCount = coachesData['hydra:totalItems'] ?? coachesData.totalItems ?? coachesData['member']?.length ?? 0
    expect(coachCount).toBe(0)

    await takeScreenshot(page, '09-db-verified-empty')
  })
})
