import { readdir, readFile, mkdir, writeFile } from 'node:fs/promises'
import { createHash } from 'node:crypto'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { parse } from 'vue/compiler-sfc'

const root=fileURLToPath(new URL('../',import.meta.url))
const declaredRevision=process.argv[2]
if(!/^[a-f0-9]{40}$/.test(declaredRevision||'')) throw new Error('Supply the declared 40-character source revision.')
async function files(dir) {
  const entries=await readdir(path.join(root,dir),{withFileTypes:true})
  return (await Promise.all(entries.map(entry=>entry.isDirectory()?files(`${dir}/${entry.name}`):[`${dir}/${entry.name}`]))).flat()
}
function baseDestinations(file) {
  if(file==='resources/js/Pages/Marketing/Home.vue') return ['UI-22']
  if(file==='resources/js/Pages/Marketing/Features.vue') return ['UI-23']
  if(file==='resources/js/Pages/Marketing/Pricing.vue') return ['UI-24']
  if(file==='resources/js/Pages/Marketing/Contact.vue'||/PrivacyPolicy|TermsOfService/.test(file)) return ['UI-26']
  if(file.includes('/Auth/')) return ['UI-25']
  if(file.includes('/Profile/')) return ['UI-18']
  if(file.includes('Calculator')) return ['UI-15']
  if(file.includes('Scanner')) return ['UI-14']
  if(file.includes('AiExport')) return ['UI-16']
  if(file.includes('EodHealth')) return ['UI-17']
  if(file.includes('LeftPanel')) return ['UI-05']
  if(/Dex|Skew|ExpiryPressure/.test(file)) return ['UI-07']
  if(/TermTile|VRPTile|Seasonality/.test(file)) return ['UI-09']
  if(/UnusualActivity/.test(file)) return ['UI-10']
  if(file.includes('NetGex')) return ['UI-11','UI-14']
  if(file.includes('StrikeDelta')) return ['UI-11']
  if(file.includes('VolumeDelta')) return ['UI-11','UI-12']
  if(/VolOverOi|PcrByStrike|PremiumByStrike/.test(file)) return ['UI-13']
  if(/QScore|OiDistribution|VolDistribution/.test(file)) return ['UI-08']
  if(file==='resources/js/Components/Dashboard.vue') return ['UI-06','UI-08','UI-09','UI-10','UI-11','UI-12','UI-13']
  if(file==='resources/js/Pages/Dashboard.vue'||/AppShell|AppLayout/.test(file)) return ['UI-04']
  if(file.includes('/Layouts/MarketingLayout')) return ['UI-20']
  if(file.includes('/Marketing/')) return ['UI-20','UI-21','UI-22','UI-23','UI-24']
  return []
}
function resolveVueImport(from, spec, knownFiles) {
  let candidate
  if(spec.startsWith('@/')) candidate=`resources/js/${spec.slice(2)}`
  else if(spec.startsWith('.')) candidate=path.posix.normalize(path.posix.join(path.posix.dirname(from),spec))
  else return null
  if(knownFiles.has(candidate)) return candidate
  return knownFiles.has(`${candidate}.vue`)?`${candidate}.vue`:null
}

const vueFiles=(await files('resources/js')).filter(file=>file.endsWith('.vue')&&!file.includes('/UI/')).sort()
const knownFiles=new Set(vueFiles)
const records=[]
for(const file of vueFiles) records.push({file,source:await readFile(path.join(root,file),'utf8')})
for(const record of records) {
  const imports=[]
  for(const match of record.source.matchAll(/from\s+['"]([^'"]+)['"]/g)) {
    const resolved=resolveVueImport(record.file,match[1],knownFiles)
    if(resolved) imports.push(resolved)
  }
  record.imports=[...new Set(imports)].sort()
}
const callers=new Map(vueFiles.map(file=>[file,[]]))
for(const record of records) for(const imported of record.imports) callers.get(imported).push(record.file)
function destinations(file,seen=new Set()) {
  if(seen.has(file)) return []
  const direct=baseDestinations(file)
  if(direct.length) return direct
  const next=new Set(seen).add(file)
  return [...new Set((callers.get(file)||[]).flatMap(caller=>destinations(caller,next)))].sort()
}

const sources=[]
for(const record of records) {
  const {file,source}=record
  const {descriptor,errors}=parse(source,{filename:file})
  if(errors.length) throw new Error(`Cannot parse ${file}: ${errors.join(', ')}`)
  const entries=[]
  function visit(node) {
    if(node.type===2 && node.content.trim()) entries.push({kind:'static-text',line:node.loc.start.line,text:node.content.trim()})
    if(node.type===5) entries.push({kind:'display',line:node.loc.start.line,expression:node.content.content})
    if(node.type===1) {
      for(const prop of node.props||[]) {
        if(prop.type===7 && ['bind','model','on','if','else-if','show','for','text','html'].includes(prop.name)) entries.push({kind:prop.name,argument:prop.arg?.content||null,line:prop.loc.start.line,expression:prop.exp?.content||''})
        if(prop.type===6 && !['class','style'].includes(prop.name)) entries.push({kind:'static-attribute',argument:prop.name,line:prop.loc.start.line,value:prop.value?.content??true})
      }
    }
    for(const child of node.children||[]) visit(child)
  }
  if(descriptor.template?.ast) visit(descriptor.template.ast)
  const heuristicReferences=source.split(/\r?\n/).flatMap((line,i)=>/\.toFixed\(|\.slice\(|\.map\(|\.reduce\(|axios\.|\/api\/|tooltip|formatter/i.test(line)?[{line:i+1,source:line.trim()}]:[])
  const mapped=destinations(file)
  sources.push({
    file,
    sha256:createHash('sha256').update(source).digest('hex'),
    callers:(callers.get(file)||[]).sort(),
    destinations:mapped,
    destinationStatus:mapped.length?'mapped from the file or its Vue callers':'UNRESOLVED — inspect route and non-Vue callers before migration',
    verification:'Compare static text, dynamic bindings, actions, conditions, source series and formatters against the inspected file. Preserve full raw rows and exact-value access. Reconcile units and scopes with inventory.md before changing the destination screen.',
    templateEntries:entries,
    heuristicReferences,
  })
}
const dir=path.join(root,'docs/ui-refresh/batch-1')
await mkdir(dir,{recursive:true})
await writeFile(path.join(dir,'source-inventory.json'),JSON.stringify({
  declaredRevision,
  provenance:'The revision is an operator-supplied label. This script reads the current working tree; verify checkout HEAD separately. SHA-256 values verify the inspected file contents.',
  coverage:'Static template text and attributes, dynamic Vue template bindings, direct Vue callers, and heuristic source references. Imported JS helpers, PHP/routes, API schemas, runtime-generated labels, assets, deployed behavior, and complete formula extraction require separate review.',
  sources,
},null,2)+'\n')
console.log(`Inventoried ${sources.length} Vue files and ${sources.reduce((sum,item)=>sum+item.templateEntries.length,0)} template entries; ${sources.filter(item=>!item.destinations.length).length} destination mappings remain unresolved.`)
