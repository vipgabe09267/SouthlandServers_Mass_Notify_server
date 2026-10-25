#!/usr/bin/env python3
"""Browser display cursor, outage and absolute-expiry contract checks."""
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]


class BrowserDisplay(unittest.TestCase):
    def test_live_polling_and_absolute_expiry(self):
        script = r"""
const assert=require('node:assert/strict'), Feed=require(FILE);
const now=Date.now(), row=n=>({id:'event-'+n,title:'Fixture',message:'Instructions',is_test:true,created_at:new Date(now-1000).toISOString()});
const response=(events,cursor,more=false,gap=false)=>({data:{ok:true,events,last_event_id:cursor,has_more:more,cursor_gap:gap},bytes:100,serverTime:now});
(async()=>{
 const feed=new Feed();
 assert.deepEqual(await feed.poll(async cursor=>{assert.equal(cursor,null);return response([row(1)],'event-1');}),[]);
 let page=0;const seen=[];
 const events=await feed.poll(async cursor=>{seen.push(cursor);page++;return response(Array.from({length:page===3?50:100},(_,i)=>row((page-1)*100+i+2)),'event-'+(page===3?251:page*100+1),page<3);});
 assert.equal(events.length,250);assert.equal(events[0].id,'event-2');assert.equal(events[249].id,'event-251');
 assert.deepEqual(seen,['event-1','event-101','event-201']);
 await assert.rejects(feed.poll(async()=>{throw Error('offline');}));assert.equal(feed.cursor,null);
 assert.deepEqual(await feed.poll(async()=>response([row(252)],'event-252')),[]);
 await assert.rejects(feed.poll(async()=>response([],'event-252',false,true)));assert.equal(feed.cursor,null);
 await feed.poll(async()=>response([],'event-1'));
 await assert.rejects(feed.poll(async()=>response([row(2)],'event-1',true)));assert.equal(feed.cursor,null);
 await feed.poll(async()=>response([],'event-1'));
 await assert.rejects(feed.poll(async()=>({...response([row(2)],'event-2'),bytes:9000000})));assert.equal(feed.cursor,null);
 const old={...row(1),created_at:new Date(now-601000).toISOString(),display_timeout:0};
 assert.equal(Feed.event(old,now),null);
 const expiry=new Date(now+1000).toISOString();const exact={...row(1),display_expires_at:expiry};
 assert.equal(Feed.event(exact,now).expires,Date.parse(expiry));assert.equal(Feed.event(exact,now+1001),null);
 assert.equal(Feed.event({...row(1),expires:expiry},now).expires,Date.parse(expiry));
 assert.equal(Feed.event({...row(1),delivery_expires_at:expiry},now).expires,Date.parse(expiry));
 assert.equal(Feed.event({...row(1),display_timeout_seconds:2},now).expires,now+1000);
 assert.equal(Feed.event({...row(1),image_url:'https://example.test/api/sipnotify/desktop/media?event_id=event-1',image_authentication:'desktop_basic'},now).imageAuthentication,true);
 assert.equal(Feed.event({...row(1),message_type:'cancel'},now),null);
 for(const bad of [{...row(1),is_test:'true'},{...row(1),created_at:'2026-10-23T01:00:00'},{...row(1),display_expires_at:'invalid'},
  {...row(1),created_at:new Date(now+31000).toISOString()},{...row(1),id:'x\n'},{...row(1),display_timeout:-1}])assert.throws(()=>Feed.event(bad,now));
 assert.throws(()=>Feed.event(row(1),NaN));
 let t=0;const deadlineFeed=new Feed();await assert.rejects(deadlineFeed.poll(async()=>{t=10001;return response([],'x');},()=>t));
 assert.equal(deadlineFeed.cursor,null);
 console.log('Browser display: baseline exclusion, 250-event bursts, cursor gaps, outages, byte/deadline bounds, typed content and absolute expiry passed.');
})().catch(error=>{console.error(error);process.exit(1)});
"""
        result = subprocess.run(
            ["node"],
            input=script.replace(
                "FILE", json.dumps(str(ROOT / "slsmassnotifyserver/portal/display.js"))
            ),
            text=True,
            capture_output=True,
            timeout=20,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("250-event bursts", result.stdout)


if __name__ == "__main__":
    unittest.main()
