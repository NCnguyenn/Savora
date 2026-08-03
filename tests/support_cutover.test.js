'use strict';
const test=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const path=require('node:path');const root=path.resolve(__dirname,'..');const read=file=>fs.readFileSync(path.join(root,file),'utf8');
test('Driver issue reporting opens a server support case',()=>{const source=read('js/driver_delivery.js');assert.match(source,/api\/support\.php/);assert.match(source,/open_case/);assert.doesNotMatch(source,/not available until the support service is connected/i);assert.match(read('api/support.php'),/support_open_case/);});
