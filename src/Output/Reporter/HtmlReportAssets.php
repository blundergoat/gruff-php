<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

/**
 * Provides the inline CSS and JavaScript assets used by the standalone HTML report.
 */
final readonly class HtmlReportAssets
{
    /**
     * Inline JavaScript that powers the interactive finding filters (severity, pillar, path, text, group-by).
     *
     * @return string - the client-side filter script body that wires controls to live filtering and URL-hash state
     */
    public static function interactiveScript(): string
    {
        return <<<'JS'
const form=document.querySelector('[data-finding-filters]');
const list=document.querySelector('[data-findings-list]');
if(form&&list){
const severitySelect=form.elements.severity;
const pillarSelect=form.elements.pillar;
const pathInput=form.elements.path;
const queryInput=form.elements.q;
const countOutput=form.querySelector('[data-filter-count]');
const clearButton=form.querySelector('[data-clear-filters]');
const severityOrder=Array.from(severitySelect.options).map(option=>option.value);
const pillarOrder=Array.from(pillarSelect.options).map(option=>option.value);
const groupOrder=['none','file','rule'];
const source=Array.from(list.querySelectorAll('.finding')).map((node,index)=>({index,node:node.cloneNode(true),severity:node.dataset.severity||'',pillar:node.dataset.pillar||'',file:node.dataset.file||'',rule:node.dataset.rule||'',search:(node.dataset.search||'').toLowerCase()}));
function selected(select){return Array.from(select.selectedOptions).map(option=>option.value);}
function setSelected(select,values){const allowed=new Set(values);Array.from(select.options).forEach(option=>{option.selected=allowed.has(option.value);});}
function radio(){const checked=form.querySelector('input[name="group"]:checked');return checked?checked.value:'none';}
function setRadio(value){const target=groupOrder.includes(value)?value:'none';const input=form.querySelector('input[name="group"][value="'+target+'"]');if(input){input.checked=true;}}
function parseList(value,allowed){if(!value){return [];}const allowedSet=new Set(allowed);return value.split(',').map(item=>item.trim()).filter(item=>allowedSet.has(item));}
function readHash(){const params=new URLSearchParams(window.location.hash.replace(/^#/,''));setSelected(severitySelect,parseList(params.get('severity'),severityOrder));setSelected(pillarSelect,parseList(params.get('pillar'),pillarOrder));pathInput.value=params.get('path')||'';queryInput.value=params.get('q')||'';setRadio(params.get('group')||'none');}
function filters(){return{severity:selected(severitySelect),pillar:selected(pillarSelect),path:pathInput.value.trim().toLowerCase(),q:queryInput.value.trim().toLowerCase(),group:radio()};}
function writeHash(){const state=filters();const parts=[];const orderedSeverity=severityOrder.filter(value=>state.severity.includes(value));const orderedPillar=pillarOrder.filter(value=>state.pillar.includes(value));if(orderedSeverity.length){parts.push('severity='+orderedSeverity.map(encodeURIComponent).join(','));}if(orderedPillar.length){parts.push('pillar='+orderedPillar.map(encodeURIComponent).join(','));}if(state.path){parts.push('path='+encodeURIComponent(state.path));}if(state.q){parts.push('q='+encodeURIComponent(state.q));}if(state.group!=='none'){parts.push('group='+encodeURIComponent(state.group));}const next=parts.length?'#'+parts.join('&'):window.location.pathname+window.location.search;history.replaceState(null,'',next);}
function matches(item,state){return(state.severity.length===0||state.severity.includes(item.severity))&&(state.pillar.length===0||state.pillar.includes(item.pillar))&&(state.path===''||item.file.toLowerCase().includes(state.path))&&(state.q===''||item.search.includes(state.q));}
function emptyNode(text){const node=document.createElement('div');node.className='empty';node.textContent=text;return node;}
function groupTitle(value){const node=document.createElement('h3');node.className='finding-group-title';node.textContent=value;return node;}
function render(){const state=filters();const visible=source.filter(item=>matches(item,state));list.replaceChildren();if(visible.length===0){list.append(emptyNode(source.length===0?'No findings.':'No findings match the active filters.'));}else if(state.group==='none'){visible.forEach(item=>list.append(item.node.cloneNode(true)));}else{const groups=new Map();visible.forEach(item=>{const key=state.group==='file'?item.file:item.rule;if(!groups.has(key)){groups.set(key,[]);}groups.get(key).push(item);});groups.forEach((items,key)=>{const section=document.createElement('section');section.className='finding-group';section.append(groupTitle(key));items.forEach(item=>section.append(item.node.cloneNode(true)));list.append(section);});}if(countOutput){countOutput.textContent=visible.length+' of '+source.length+' findings shown.';}}
function update(){writeHash();render();}
form.addEventListener('change',update);
form.addEventListener('input',event=>{if(event.target===pathInput||event.target===queryInput){update();}});
if(clearButton){clearButton.addEventListener('click',()=>{setSelected(severitySelect,[]);setSelected(pillarSelect,[]);pathInput.value='';queryInput.value='';setRadio('none');update();});}
window.addEventListener('hashchange',()=>{readHash();render();});
readHash();
render();
}
JS;
    }

    /**
     * Inline CSS for the report; appends diagnostic and interactive-filter rules when those sections are present.
     *
     * @param bool $hasDiagnostics - True when diagnostic-section rules should be included.
     * @param bool $interactive - True when filter-form and grouped-finding rules should be included.
     *
     * @return string - stylesheet body for the standalone HTML report, including optional diagnostic/filter sections
     */
    public static function css(bool $hasDiagnostics, bool $interactive): string
    {
        $css = <<<'CSS'
:root{--ink:#0d0c0a;--ink-2:#161412;--ink-3:#1f1c19;--paper:#f3e9d2;--paper-dim:#b5ab94;--paper-mute:#7d735f;--rule:#2a2622;--forge:#e85d04;--grade-a:#7fa15a;--grade-b:#b8b450;--grade-c:#d08c36;--grade-d:#c2552b;--grade-f:#8b2828;--advisory:#b5ab94;--serif:Georgia,'Iowan Old Style',serif;--mono:'JetBrains Mono','IBM Plex Mono',ui-monospace,monospace}*{box-sizing:border-box;margin:0;padding:0}html{background:var(--ink);scrollbar-gutter:stable}body{font-family:var(--mono);color:var(--paper);background:var(--ink);min-height:100vh;line-height:1.5;font-size:14px;padding:48px 32px}.paper{max-width:1180px;margin:0 auto 24px;background:var(--ink-2);border:1px solid var(--rule);position:relative;padding:56px 64px 48px;scrollbar-gutter:stable}.corner-tr,.corner-bl,.paper:before,.paper:after{content:'';position:absolute;width:22px;height:22px;border:1px solid var(--forge)}.paper:before{top:12px;left:12px;border-right:0;border-bottom:0}.paper:after{bottom:12px;right:12px;border-left:0;border-top:0}.corner-tr{top:12px;right:12px;border-left:0;border-bottom:0}.corner-bl{bottom:12px;left:12px;border-right:0;border-top:0}.masthead{display:grid;grid-template-columns:1fr auto;gap:32px;padding-bottom:28px;border-bottom:1px solid var(--rule);align-items:end}.wordmark{font-family:var(--serif);font-weight:900;font-size:96px;line-height:.85;color:var(--paper);font-style:italic}.wordmark:after{content:'·php';color:var(--forge);font-style:normal;font-size:.45em;margin-left:.15em;vertical-align:super}.tagline{margin-top:12px;font-size:11px;letter-spacing:.24em;color:var(--paper-mute);text-transform:uppercase}.meta{text-align:right;font-size:11px;color:var(--paper-dim);line-height:1.9}.label{color:var(--paper-mute);text-transform:uppercase;letter-spacing:.16em;margin-right:8px}.val{color:var(--paper)}.inspection-id{margin-top:10px;color:var(--forge);font-weight:700;font-size:12px;letter-spacing:.1em}.section-head{font-size:11px;letter-spacing:.32em;color:var(--paper-mute);text-transform:uppercase;padding-bottom:16px;margin-bottom:20px;border-bottom:1px solid var(--rule);display:flex;justify-content:space-between;align-items:baseline;font-family:var(--mono);font-weight:500;line-height:1.5}.section-head:before{content:'§';margin-right:10px;color:var(--forge);font-family:var(--serif);font-size:14px;font-style:italic}.aside{color:var(--paper-mute);font-size:10px;letter-spacing:.24em}.verdict{display:grid;grid-template-columns:auto 1fr;gap:56px;padding:48px 0;border-bottom:1px solid var(--rule);align-items:center}.grade-stamp{width:220px;height:220px;border:3px solid var(--grade-b);color:var(--grade-b);display:flex;flex-direction:column;align-items:center;justify-content:center;transform:rotate(-4deg)}.grade-letter{font-family:var(--serif);font-style:italic;font-weight:900;font-size:112px;line-height:1}.grade-score{font-size:13px;letter-spacing:.1em}.verdict-body{display:flex;flex-direction:column;gap:18px}.verdict-headline{font-family:var(--serif);font-style:italic;font-weight:600;font-size:38px;line-height:1.15}.verdict-headline em{color:var(--forge)}.verdict-stats{display:grid;grid-template-columns:repeat(4,1fr);border-top:1px solid var(--rule);padding-top:20px}.stat{border-right:1px solid var(--rule);padding:0 18px}.stat:first-child{padding-left:0}.stat:last-child{border-right:0}.verdict-stats .num{font-family:var(--serif);font-weight:800;font-size:32px;line-height:1}.verdict-stats .num.warn{color:var(--grade-c)}.verdict-stats .num.fail{color:var(--grade-f)}.verdict-stats .num.note{color:var(--advisory)}.lbl{font-size:10px;text-transform:uppercase;letter-spacing:.2em;color:var(--paper-mute);margin-top:8px}.score-context{border-top:1px solid var(--rule);padding-top:16px;color:var(--paper-dim);font-size:12px}.score-context-title{font-size:10px;text-transform:uppercase;letter-spacing:.18em;color:var(--paper-mute);margin-bottom:8px}.score-context ul{display:grid;gap:6px;margin-left:18px}.pillars,.offenders,.chart-section{padding:48px 0;border-bottom:1px solid var(--rule)}.pillar-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:var(--rule);border:1px solid var(--rule)}.pillar{background:var(--ink-2);padding:24px 20px;display:flex;flex-direction:column;gap:14px}.pillar .name{font-size:10px;text-transform:uppercase;letter-spacing:.24em;color:var(--paper-mute)}.pillar .grade{font-family:var(--serif);font-weight:800;font-style:italic;font-size:52px;line-height:.9}.grade.a,.grade-pill.a{color:var(--grade-a)}.grade.b,.grade-pill.b{color:var(--grade-b)}.grade.c,.grade-pill.c{color:var(--grade-c)}.grade.d,.grade-pill.d{color:var(--grade-d)}.grade.f,.grade-pill.f{color:var(--grade-f)}.breakdown{font-size:11px;color:var(--paper-dim);line-height:1.7}.row{display:flex;justify-content:space-between;gap:8px}.key{color:var(--paper-mute)}table{width:100%;border-collapse:collapse;font-size:13px;table-layout:auto;font-family:var(--mono)}th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.12em;color:var(--paper-mute);font-weight:500;padding:12px 14px 12px 0;border-bottom:1px solid var(--rule)}th:last-child,td:last-child{padding-right:0}th.num,td.num{text-align:right;padding-left:18px}td{padding:14px 14px 14px 0;border-bottom:1px solid var(--ink-3);color:var(--paper-dim);font-size:13px;font-family:var(--mono);font-weight:500;line-height:1.4}td.num{color:var(--paper);font-variant-numeric:tabular-nums}.file-path{color:var(--paper);font-weight:500}.grade-pill{display:inline-block;font-family:var(--serif);font-style:italic;font-weight:800;font-size:18px;line-height:1;padding:4px 10px;border:1.5px solid currentColor;min-width:36px;text-align:center}.chart-summary{color:var(--paper-dim);font-size:12px;margin:-6px 0 18px}.chart-card{border:1px solid var(--rule);padding:24px;background:var(--ink-3)}.title{font-size:10px;text-transform:uppercase;letter-spacing:.2em;color:var(--paper-mute);margin-bottom:24px}.histogram{display:flex;align-items:flex-end;gap:6px;height:180px;padding-bottom:20px;border-bottom:1px solid var(--rule)}.bar{flex:1;background:var(--forge);position:relative;min-height:4px}.bar.warn{background:var(--grade-c)}.bar.fail{background:var(--grade-f)}.bar .count{position:absolute;top:-22px;left:50%;transform:translateX(-50%);font-size:11px}.histogram-axis{display:flex;gap:6px;margin-top:8px;font-size:10px;color:var(--paper-mute)}.histogram-axis span{flex:1;text-align:center}.findings{padding:48px 0}.finding{display:grid;grid-template-columns:auto 1fr auto;gap:24px;padding:18px 0;border-bottom:1px solid var(--ink-3);align-items:start}.severity{font-size:9px;text-transform:uppercase;letter-spacing:.24em;padding:4px 10px;border:1px solid currentColor;margin-top:2px;min-width:76px;text-align:center}.severity.fail{color:var(--grade-f)}.severity.warn{color:var(--grade-c)}.severity.note{color:var(--paper-mute)}.rule{font-size:10px;color:var(--forge);text-transform:uppercase;letter-spacing:.16em;margin-bottom:6px;font-family:var(--mono);font-weight:700;line-height:1.5}.msg{font-family:var(--serif);font-weight:500;font-size:17px;color:var(--paper);line-height:1.4}.loc{font-size:11px;color:var(--paper-mute);margin-top:8px}.loc code{color:var(--paper-dim);background:var(--ink-3);padding:1px 6px;border:1px solid var(--rule)}.loc-link{color:inherit;text-decoration:none}.loc-link[href]{text-decoration:underline;text-decoration-color:var(--rule);text-underline-offset:3px}.loc-link:focus-visible{outline:2px solid var(--forge);outline-offset:3px}.points{font-size:10px;color:var(--paper-mute);text-align:right;letter-spacing:.1em;min-width:96px;padding-left:12px}.empty{color:var(--paper-dim);font-size:12px}.footer{margin-top:48px;padding-top:24px;border-top:1px solid var(--rule);display:grid;grid-template-columns:1fr auto 1fr;gap:24px;align-items:center;font-size:10px;color:var(--paper-mute);letter-spacing:.12em;text-transform:uppercase}.center{font-family:var(--serif);font-style:italic;font-size:13px;color:var(--paper-dim);text-transform:none;letter-spacing:0}.right{text-align:right}@media(max-width:900px){body{padding:16px}.paper{padding:28px 20px}.wordmark{font-size:64px}.masthead,.verdict{grid-template-columns:1fr}.meta{text-align:left}.grade-stamp{margin:0 auto}.pillar-grid{grid-template-columns:repeat(2,1fr)}.verdict-stats{grid-template-columns:repeat(2,1fr);gap:16px}.stat{border-right:0;padding:0}.verdict-headline{font-size:28px}}
CSS;

        if ($hasDiagnostics) {
            $css .= <<<'CSS'
.diagnostics{padding:28px 0 0}.diagnostic-list{display:grid;gap:10px}.diagnostic{display:grid;grid-template-columns:auto 1fr;gap:10px 14px;border:1px solid var(--rule);background:var(--ink-3);padding:12px 14px;color:var(--paper-dim);font-size:12px}.diagnostic-type{text-transform:uppercase;letter-spacing:.14em;color:var(--forge);font-size:10px}.diagnostic-location{grid-column:2;color:var(--paper-mute);font-size:11px}
CSS;
        }

        if (!$interactive) {
            return $css;
        }

        return $css . <<<'CSS'
.finding-filters{border:1px solid var(--rule);background:var(--ink-3);padding:18px;margin:0 0 22px;display:grid;gap:16px}.filter-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}.finding-filters label,.filter-group legend{display:flex;flex-direction:column;gap:7px;color:var(--paper-mute);font-size:10px;text-transform:uppercase;letter-spacing:.14em}.finding-filters input,.finding-filters select{width:100%;border:1px solid var(--rule);background:var(--ink);color:var(--paper);padding:8px 10px;font:12px var(--mono)}.finding-filters select{min-height:96px}.finding-filters input:focus-visible,.finding-filters select:focus-visible,.finding-filters button:focus-visible{outline:2px solid var(--forge);outline-offset:3px}.filter-group{border:0;display:flex;align-items:center;gap:14px;flex-wrap:wrap}.filter-group legend{margin-right:4px}.filter-group .radio{flex-direction:row;align-items:center;text-transform:none;letter-spacing:0;font-size:12px;color:var(--paper-dim)}.filter-group input{width:auto}.filter-actions{display:flex;justify-content:space-between;align-items:center;gap:16px}.filter-actions button{border:1px solid var(--forge);background:var(--forge);color:var(--ink);padding:9px 12px;font:700 12px var(--mono);cursor:pointer}.filter-count{color:var(--paper-dim);font-size:12px}.finding-group{border-top:1px solid var(--rule);padding-top:10px}.finding-group-title{font:700 11px var(--mono);letter-spacing:.14em;text-transform:uppercase;color:var(--paper-dim);margin:12px 0 2px}@media(max-width:900px){.filter-grid{grid-template-columns:1fr 1fr}.filter-actions{align-items:flex-start;flex-direction:column}}@media(max-width:560px){.filter-grid{grid-template-columns:1fr}.finding{grid-template-columns:1fr}.points{text-align:left;padding-left:0}}
CSS;
    }
}
