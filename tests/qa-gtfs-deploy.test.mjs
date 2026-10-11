import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

test('QA deployment does not refresh or rebuild unused static GTFS data',()=>{
 const workflow=fs.readFileSync('.github/workflows/deploy-qa.yml','utf8');
 assert.doesNotMatch(workflow,/Refresh private QA GTFS timetable|Build private QA GTFS index/);
 assert.doesNotMatch(workflow,/php ['"]?\$NAMECHEAP_DEPLOY_PATH\/qatest\/api\/gtfs-(?:update|index)\.php/);
 assert.match(workflow,/Deploy only into QA subdirectory/);
 assert.match(workflow,/Verify QA API and page/);
 assert.match(workflow,/php -l api\/gtfs-update\.php/);
 assert.match(workflow,/php -l api\/gtfs-index\.php/);
});

test('CLI-only static GTFS maintenance tools stay available for future scheduling',()=>{
 for(const file of ['api/gtfs-update.php','api/gtfs-index.php']){
  const contents=fs.readFileSync(file,'utf8');
  assert.match(contents,/PHP_SAPI!=='cli'/);
 }
});
