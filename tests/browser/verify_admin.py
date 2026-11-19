from playwright.sync_api import sync_playwright
from pathlib import Path
import os
with sync_playwright() as p:
 browser=p.chromium.launch(executable_path=os.environ.get('PLAYWRIGHT_EXECUTABLE_PATH', r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'),headless=True)
 page=browser.new_page(viewport={'width':1440,'height':1000})
 errors=[]; page.on('console',lambda msg: print('BROWSER',msg.type,msg.text) if msg.type=='error' else None); page.on('pageerror',lambda e:errors.append(str(e)))
 page.goto('http://127.0.0.1:8099/i/')
 page.locator('#username').fill('demo'); page.locator('#passwordPlain').fill('demo'); page.locator('#loginButton').click()
 page.wait_for_load_state('networkidle')
 page.goto('http://127.0.0.1:8099/i/?c=extension&a=configure&e=ArticleTopics')
 page.wait_for_selector('#topic-rows')
 assert page.locator('#topic-rows tr').count()==13
 assert 'ui-test-sentinel-key' not in page.content()
 page.locator('[name=preview_title]').fill('Nintendo announces new Zelda game')
 page.locator('[name=preview_body]').fill('Nintendo revealed a new Zelda game.')
 page.get_by_role('button',name='Preview',exact=True).click()
 assert 'Video Games' in page.locator('.alert-success').inner_text()
 print('PASS: authenticated admin page renders and read-only preview classifies')
 page.get_by_role('button',name='Check provider',exact=True).click()
 assert 'Provider responded successfully' in page.locator('.alert-success').inner_text()
 print('PASS: explicit provider health check succeeds')
 page.get_by_role('button',name='Add topic',exact=True).click()
 assert page.locator('#topic-rows tr').count()==14
 page.locator('#topic-rows tr').last.get_by_role('button',name='Delete',exact=True).click()
 assert page.locator('#topic-rows tr').count()==13
 page.get_by_role('button',name='Save settings',exact=True).click()
 assert 'Configuration saved' in page.locator('.alert-success').inner_text()
 assert page.locator('#label_prefix').input_value()=='Topic: '
 assert 'ui-test-sentinel-key' not in page.content()
 print('PASS: topic add/delete and settings save preserve prefix and hide key')
 page.locator('[name=backfill_scope]').select_option('all')
 page.locator('[name=backfill_dry_run]').check()
 page.get_by_role('button',name='Queue backfill',exact=True).click()
 assert 'Backfill queued' in page.locator('.alert-success').inner_text()
 print('PASS: UI queues a dry-run all-articles backfill')
 bad=page.request.post(page.url,form={'_csrf':'invalid','admin_action':'backfill','backfill_scope':'all'})
 assert bad.status>=400, 'CSRF write unexpectedly accepted'
 print('PASS: missing/invalid CSRF token rejected:',bad.status)
 page.screenshot(path='.tools/admin-desktop.png',full_page=True)
 print('JS errors:',errors)
 assert not errors
 browser.close()
