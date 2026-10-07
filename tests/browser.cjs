/* Optional real-browser check. Demo credentials must be in a private temporary file.
   SHAREMANAGER_DEMO_FILE=/tmp/... node tests/browser.cjs
   Requires Playwright and a loopback-only CDP browser on port 9222. */
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
(async () => {
    const demo = JSON.parse(fs.readFileSync(process.env.SHAREMANAGER_DEMO_FILE, 'utf8'));
    const directory = path.join(__dirname, '../docs/screenshots');
    fs.mkdirSync(directory, {recursive:true});
    const browser = await chromium.connectOverCDP('http://127.0.0.1:9222');
    const context = await browser.newContext({viewport:{width:1440,height:1100},locale:'de-DE'});
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    try {
        await page.goto('http://127.0.0.1:8080/index.php/login');
        await page.locator('#user').fill(demo.uid);
        await page.locator('#password').fill(demo.password);
        await page.locator('button[type="submit"]').click();
        await page.waitForURL(url => !url.pathname.endsWith('/login'));
        await page.goto('http://127.0.0.1:8080/index.php/apps/sharemanager/');
        await page.waitForFunction(() => document.querySelectorAll('.share-card').length === 5);
        const listRoute = '**/apps/sharemanager/shares';
        await page.route(listRoute, route => route.fulfill({status:200, contentType:'text/html', body:'Invalid JSON'}));
        await page.locator('#share-refresh').click();
        await page.waitForFunction(() => document.getElementById('share-status').textContent.includes('Ungültige Serverantwort'));
        assert.equal(await page.locator('.share-card').count(),0);
        await page.unroute(listRoute);
        await page.locator('#share-refresh').click();
        await page.waitForFunction(() => document.querySelectorAll('.share-card').length === 5);
        console.log('PASS: Invalid server response is surfaced and inventory reload recovers');
        const tree = page.locator('#share-tree');
        // Compact initial overview; child headers still show the tree and effective user counts.
        const childDetails = await page.locator('.tree-section > .tree-node > .tree-node-body > .tree-node').all();
        for (const details of childDetails) await details.locator(':scope > summary').click();
        await page.screenshot({path:path.join(directory,'01-tree.png')});
        async function filter(id, enabled, count) {
            await page.locator('#'+id).setChecked(enabled);
            await page.waitForFunction(expected => document.querySelectorAll('.share-card').length === expected, count);
        }
        await filter('filter-foreign',true,4);
        await tree.screenshot({path:path.join(directory,'02-fremde-freigaben.png')});
        await filter('filter-foreign',false,5);
        await filter('filter-below',true,3);
        await tree.screenshot({path:path.join(directory,'03-unterhalb-top.png')});
        await filter('filter-below',false,5);
        await filter('filter-deviation',true,4);
        await tree.screenshot({path:path.join(directory,'04-abweichungen.png')});
        await filter('filter-foreign',true,4);
        await filter('filter-below',true,3);
        await page.locator('#share-search').fill(demo.people.D);
        await page.waitForFunction(() => document.querySelectorAll('.share-card').length === 1);
        assert.match(await tree.innerText(), new RegExp(demo.people.C));
        console.log('PASS: Real browser checks all three filters, combined filters and recipient search');
        await page.locator('#share-search').click();
        await page.locator('#share-search').press('Control+A');
        await page.locator('#share-search').press('Backspace');
        await filter('filter-below',false,4);
        await filter('filter-foreign',false,4);
        await filter('filter-deviation',false,5);
        await page.locator('#revoke-root').selectOption(String(demo.rootId));
        await page.locator('#revoke-user').selectOption(demo.people.D);
        await page.locator('#revoke-preview').click();
        await page.waitForFunction(() => !document.getElementById('revoke-confirm').disabled);
        assert.equal(await page.locator('#revoke-plan li').count(),1);
        assert.match(await page.locator('#revoke-plan').innerText(),new RegExp(demo.people.C));
        await page.locator('.revoke-panel').screenshot({path:path.join(directory,'05-entzug-vorschau.png')});
        page.once('dialog', dialog => dialog.dismiss());
        await page.locator('#revoke-confirm').click();
        assert.equal(await page.locator('.share-card').count(),5);
        console.log('PASS: Real browser cancellation leaves grants unchanged');
        const route = '**/apps/sharemanager/revoke';
        await page.route(route, route => route.fulfill({status:409,contentType:'application/json',body:JSON.stringify({error:'Freigaben haben sich geändert. Vorschau erneut laden.'})}));
        page.once('dialog', dialog => dialog.accept());
        await page.locator('#revoke-confirm').click();
        await page.locator('.revoke-result').waitFor();
        assert.match(await page.locator('.revoke-result').innerText(),/geändert/);
        assert.equal(await page.locator('.share-card').count(),5);
        assert.equal(await page.locator('#revoke-confirm').isDisabled(),true);
        await page.unroute(route);
        console.log('PASS: Real browser stale-plan error is visible; confirmation invalidated');
        await page.locator('#revoke-preview').click();
        await page.waitForFunction(() => !document.getElementById('revoke-confirm').disabled);
        page.once('dialog', dialog => dialog.accept());
        await page.locator('#revoke-confirm').click();
        await page.waitForFunction(() => document.querySelector('.revoke-result')?.textContent.includes('Aktuell kein Freigabezugriff'));
        assert.equal(await page.locator('.share-card').count(),4);
        assert.equal(await page.locator('#revoke-user option[value="'+demo.people.D+'"]').count(),0);
        assert.ok((await tree.innerText()).includes(demo.people.E));
        assert.ok((await tree.innerText()).includes(demo.people.F));
        await page.locator('.revoke-panel').screenshot({path:path.join(directory,'06-entzug-ergebnis.png')});
        assert.deepEqual(errors,[]);
        console.log('PASS: Real browser confirmed D revocation, refreshed tree and retained E/F grants; no JavaScript errors');
    } finally {
        await context.close();
        await browser.close();
    }
})().catch(error => {console.error(error.message);process.exitCode=1;});
