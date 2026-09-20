// Rounded readings transcribed from the approved Positioning reference.
// These are historical display examples, not a raw API response or live feed.
export const provenance = 'Recorded display sample · snapshot September 9, 2026 · rounded values'
const exposure = [
  ['2026-08-11',-627260],['2026-08-12',-178610],['2026-08-13',1460000],['2026-08-14',2950000],['2026-08-17',-5350000],['2026-08-18',-4830000],['2026-08-19',982720],['2026-08-20',-4850000],['2026-08-21',16420000],['2026-08-24',-2380000],['2026-08-25',1720000],['2026-08-26',-131740],['2026-08-27',4200000],['2026-08-28',-763300],['2026-08-31',-5310000],['2026-09-01',-5170000],['2026-09-02',3270000],['2026-09-03',3870000],['2026-09-04',-973520],['2026-09-08',810320],['2026-09-09',-3620000],['2026-09-10',-840070],['2026-09-11',-6680000],['2026-09-14',-340960],['2026-09-15',-988060],['2026-09-16',-229270],['2026-09-17',29820],['2026-09-18',7690000],['2026-09-21',-10640],['2026-09-22',-33300],['2026-09-23',0],['2026-09-25',-1190000],['2026-09-30',2710000],['2026-10-02',-721940],['2026-10-09',176380],['2026-10-16',-8770000],['2026-10-23',59160],['2026-10-30',1720000],['2026-11-20',-4270000],['2026-11-30',704510],
].map(([date,value])=>({date,value}))
const dates = ['07-30','07-31','08-03','08-04','08-05','08-06','08-07','08-10','08-11','08-12','08-13','08-14','08-17','08-18','08-19','08-20','08-21','08-24','08-25','08-26','08-27','08-28','08-31','09-01','09-02','09-03','09-04','09-07','09-08','09-09']
export const buckets = [
  { value:'0d',label:'0DTE',exp:'2026-09-11',put:15.9,call:13.8,skew:2.1,dod:.2,values:[2.2,0,.4,-.3,.1,-.6,.7,1.4,-.1,-.1,-.2,-.2,.4,1.5,-.1,9,.6,-.4,.6,-.4,-.2,.7,.8,.4,-.3,-.1,.4,2.9,0,0] },
  { value:'1w',label:'1W',exp:'2026-09-18',put:16.5,call:11.4,skew:5.2,dod:.7,values:[3.5,3.4,2.4,1.4,1.1,1.4,1,1.2,1.3,1.2,1.3,1.4,1.7,2.2,2.1,3.1,1.9,2.2,2.1,2.1,2,1.5,1.9,2.5,1.9,1.4,2.6,2.8,2.4,3.2] },
  { value:'1m',label:'1M',exp:'2026-10-02',put:16.1,call:11,skew:5.1,dod:.5,values:[3.9,3.6,2.5,1.8,2.1,2.3,1.8,2,2.2,1.9,1.8,2,2.5,3.2,2.7,3.6,2.7,2.9,3.9,3.7,3.3,3.4,3.8,5.4,4.8,3.8,4.9,4.9,4.8,5.3] },
]
export const scenarios = [
  { value:'recorded',label:'Recorded · dense / mixed signs' },
  { value:'sparse',label:'Synthetic · sparse / missing' },
  { value:'zero',label:'Synthetic · all zero' },
  { value:'positive',label:'Synthetic · positive only' },
  { value:'negative',label:'Synthetic · negative only' },
  { value:'empty',label:'Synthetic · empty' },
  { value:'loading',label:'Synthetic · loading' },
  { value:'preparing',label:'Synthetic · preparing data' },
  { value:'stale',label:'Synthetic · stale comparison' },
  { value:'closed',label:'Synthetic · market closed' },
  { value:'error',label:'Synthetic · failed request' },
]
export function fixture(scenario, bucketId) {
  const bucket = buckets.find(b=>b.value===bucketId) || buckets[0]
  let expiry = exposure.map(r=>({...r}))
  let history = bucket.values.map((value,i)=>({date:`2026-${dates[i]}`,value}))
  if (['empty','loading','preparing','error'].includes(scenario)) { expiry=[];history=[] }
  if (scenario==='sparse') { expiry=[expiry[0],{...expiry[1],value:null},{...expiry[2],value:0}];history=history.slice(-5).map((r,i)=>({...r,value:i===2?null:r.value})) }
  if (['zero','positive','negative'].includes(scenario)) {
    const transform = value => scenario==='zero'?0:scenario==='positive'?Math.abs(value): -Math.abs(value)
    expiry=expiry.map(r=>({...r,value:transform(r.value)}));history=history.map(r=>({...r,value:transform(r.value)}))
  }
  return { expiry,history,bucket }
}
