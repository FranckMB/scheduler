# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: smoke.spec.ts >> Smoke Test: Core User Flow >> login → wizard → generation → dashboard
- Location: tests/e2e/smoke.spec.ts:211:3

# Error details

```
Error: Channel closed
```

```
Error: locator.boundingBox: Target page, context or browser has been closed
Call log:
  - waiting for locator('[id="tier-A"]')

```

# Test source

```ts
  81  | }
  82  | 
  83  | async function fillVenueStep(page: Page) {
  84  |   await page.fill('input[placeholder="Ex: Gymnase Principal"]', 'Gymnase Principal')
  85  | 
  86  |   await page.click('text=Details')
  87  |   await page.waitForTimeout(300)
  88  | 
  89  |   const mondaySection = page.locator('h5:has-text("Lun")').locator('..')
  90  |   await mondaySection.locator('button:has-text("+ Plage")').click()
  91  |   await page.waitForTimeout(200)
  92  | 
  93  |   const wednesdaySection = page.locator('h5:has-text("Mer")').locator('..')
  94  |   await wednesdaySection.locator('button:has-text("+ Plage")').click()
  95  |   await page.waitForTimeout(200)
  96  | 
  97  |   await page.waitForTimeout(800)
  98  | }
  99  | 
  100 | async function fillVenueConstraintStep(page: Page) {
  101 |   await page.click('button:has-text("+ Ajouter une contrainte")')
  102 |   await page.waitForTimeout(200)
  103 | 
  104 |   const venueSelect = page.locator('select').first()
  105 |   await venueSelect.selectOption({ label: 'Gymnase Principal' })
  106 |   await page.waitForTimeout(500)
  107 | }
  108 | 
  109 | async function fillTeamStep(page: Page) {
  110 |   await page.click('button:has-text("+ Ajouter une equipe")')
  111 |   await page.waitForTimeout(200)
  112 | 
  113 |   await page.fill('input[placeholder="Ex: U15 Elite"]', 'U15 Elite')
  114 | 
  115 |   const categorySelect = page.locator('select').nth(0)
  116 |   const options = await categorySelect.locator('option').allTextContents()
  117 |   if (options.length > 1) {
  118 |     await categorySelect.selectOption({ index: 1 })
  119 |   }
  120 | 
  121 |   const genderSelect = page.locator('select').nth(1)
  122 |   await genderSelect.selectOption('M')
  123 | 
  124 |   await page.fill('input[type="number"]', '12')
  125 | 
  126 |   await page.click('text=Details')
  127 |   await page.waitForTimeout(200)
  128 |   const sessionsInput = page.locator('input[type="number"]').last()
  129 |   await sessionsInput.fill('2')
  130 | 
  131 |   await page.waitForTimeout(800)
  132 | }
  133 | 
  134 | async function fillTeamConstraintStep(page: Page) {
  135 |   await page.click('button:has-text("+ Ajouter")')
  136 |   await page.waitForTimeout(200)
  137 | 
  138 |   const teamSelect = page.locator('select').nth(0)
  139 |   await teamSelect.selectOption({ label: 'U15 Elite' })
  140 | 
  141 |   const typeSelect = page.locator('select').nth(1)
  142 |   await typeSelect.selectOption('preferred')
  143 | 
  144 |   const venueSelect = page.locator('select').nth(3)
  145 |   await venueSelect.selectOption({ label: 'Gymnase Principal' })
  146 | 
  147 |   await page.waitForTimeout(500)
  148 | }
  149 | 
  150 | async function fillCoachStep(page: Page) {
  151 |   await page.click('button:has-text("+ Ajouter un coach")')
  152 |   await page.waitForTimeout(200)
  153 | 
  154 |   await page.fill('input[placeholder="Nom du coach"]', 'Coach Jean')
  155 | 
  156 |   await page.click('text=Details')
  157 |   await page.waitForTimeout(200)
  158 | 
  159 |   await page.click('button:has-text("U15 Elite")')
  160 |   await page.waitForTimeout(500)
  161 | }
  162 | 
  163 | async function fillCoachConstraintStep(page: Page) {
  164 |   await page.click('button:has-text("+ Ajouter")')
  165 |   await page.waitForTimeout(200)
  166 | 
  167 |   const coachSelect = page.locator('select').nth(0)
  168 |   await coachSelect.selectOption({ label: 'Coach Jean' })
  169 | 
  170 |   const venueSelect = page.locator('select').nth(2)
  171 |   await venueSelect.selectOption({ label: 'Gymnase Principal' })
  172 | 
  173 |   await page.waitForTimeout(500)
  174 | }
  175 | 
  176 | async function fillTierListStep(page: Page) {
  177 |   const teamCard = page.locator('.cursor-grab:has-text("U15 Elite")')
  178 |   const tierADropZone = page.locator('[id="tier-A"]')
  179 | 
  180 |   const cardBox = await teamCard.boundingBox()
> 181 |   const dropBox = await tierADropZone.boundingBox()
      |                                       ^ Error: locator.boundingBox: Target page, context or browser has been closed
  182 | 
  183 |   if (cardBox && dropBox) {
  184 |     await page.mouse.move(cardBox.x + cardBox.width / 2, cardBox.y + cardBox.height / 2)
  185 |     await page.mouse.down()
  186 |     await page.mouse.move(dropBox.x + dropBox.width / 2, dropBox.y + dropBox.height / 2, { steps: 10 })
  187 |     await page.mouse.up()
  188 |   }
  189 | 
  190 |   await page.waitForTimeout(500)
  191 | }
  192 | 
  193 | async function fillValidationStep(page: Page) {
  194 |   await page.waitForTimeout(500)
  195 | }
  196 | 
  197 | async function fillSummaryStep(page: Page) {
  198 |   await page.waitForSelector('text=Resume et Generation')
  199 |   await page.waitForTimeout(500)
  200 | }
  201 | 
  202 | test.describe('Smoke Test: Core User Flow', () => {
  203 |   test.beforeEach(async ({ page }) => {
  204 |     await page.goto('/')
  205 |     await page.evaluate(() => {
  206 |       localStorage.clear()
  207 |       sessionStorage.clear()
  208 |     })
  209 |   })
  210 | 
  211 |   test('login → wizard → generation → dashboard', async ({ page, request }) => {
  212 |     test.setTimeout(120000)
  213 | 
  214 |     // ── 1. Login ──
  215 |     await login(page, request)
  216 |     await takeScreenshot(page, '01-login-success')
  217 | 
  218 |     // ── 2. Navigate to wizard ──
  219 |     await page.goto('/wizard')
  220 |     await page.waitForSelector('text=Assistant de configuration')
  221 |     await takeScreenshot(page, '02-wizard-start')
  222 | 
  223 |     // ── Step 0: Venues ──
  224 |     await fillVenueStep(page)
  225 |     await takeScreenshot(page, '03-step-0-venues')
  226 |     await page.click('button:has-text("Suivant")')
  227 |     await page.waitForTimeout(500)
  228 | 
  229 |     // ── Step 1: Venue Constraints ──
  230 |     await fillVenueConstraintStep(page)
  231 |     await takeScreenshot(page, '04-step-1-venue-constraints')
  232 |     await page.click('button:has-text("Suivant")')
  233 |     await page.waitForTimeout(500)
  234 | 
  235 |     // ── Step 2: Teams ──
  236 |     await fillTeamStep(page)
  237 |     await takeScreenshot(page, '05-step-2-teams')
  238 |     await page.click('button:has-text("Suivant")')
  239 |     await page.waitForTimeout(500)
  240 | 
  241 |     // ── Step 3: Team Constraints ──
  242 |     await fillTeamConstraintStep(page)
  243 |     await takeScreenshot(page, '06-step-3-team-constraints')
  244 |     await page.click('button:has-text("Suivant")')
  245 |     await page.waitForTimeout(500)
  246 | 
  247 |     // ── Step 4: Coaches ──
  248 |     await fillCoachStep(page)
  249 |     await takeScreenshot(page, '07-step-4-coaches')
  250 |     await page.click('button:has-text("Suivant")')
  251 |     await page.waitForTimeout(500)
  252 | 
  253 |     // ── Step 5: Coach Constraints ──
  254 |     await fillCoachConstraintStep(page)
  255 |     await takeScreenshot(page, '08-step-5-coach-constraints')
  256 |     await page.click('button:has-text("Suivant")')
  257 |     await page.waitForTimeout(500)
  258 | 
  259 |     // ── Step 6: Tier List ──
  260 |     await fillTierListStep(page)
  261 |     await takeScreenshot(page, '09-step-6-tier-list')
  262 |     await page.click('button:has-text("Suivant")')
  263 |     await page.waitForTimeout(500)
  264 | 
  265 |     // ── Step 7: Validation ──
  266 |     await fillValidationStep(page)
  267 |     await takeScreenshot(page, '10-step-7-validation')
  268 |     await page.click('button:has-text("Suivant")')
  269 |     await page.waitForTimeout(500)
  270 | 
  271 |     // ── Step 8: Summary & Generate ──
  272 |     await fillSummaryStep(page)
  273 |     await takeScreenshot(page, '11-step-8-summary')
  274 | 
  275 |     // Click generate — opens preview modal
  276 |     await page.click('button:has-text("Generer le planning")')
  277 |     await page.waitForTimeout(800)
  278 |     await takeScreenshot(page, '12-generate-preview-modal')
  279 | 
  280 |     // Confirm generation in modal
  281 |     await page.click('button:has-text("Lancer la generation")')
```