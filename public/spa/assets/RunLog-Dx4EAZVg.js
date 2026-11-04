import{G as i,h as t,c as n,b as u,n as o,j as a,Q as x,t as s,q as c,e as d,F as y,p as g,E as f,x as b}from"./index-BPeB5oQb.js";/**
 * @license lucide-vue-next v1.0.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const v=i("circle-check",[["circle",{cx:"12",cy:"12",r:"10",key:"1mglay"}],["path",{d:"m9 12 2 2 4-4",key:"dzmm74"}]]);/**
 * @license lucide-vue-next v1.0.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const w=i("triangle-alert",[["path",{d:"m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3",key:"wmoenq"}],["path",{d:"M12 9v4",key:"juzpu7"}],["path",{d:"M12 17h.01",key:"p32p05"}]]),C={class:"mb-2 flex items-center gap-2 text-sm font-medium"},p={key:3},B={key:4},q={key:5},A={key:6,class:"text-red-800"},L={key:7,class:"ml-auto text-xs font-normal text-ink-400"},N={class:"max-h-48 overflow-y-auto font-mono text-[11.5px] leading-relaxed text-ink-600"},E={__name:"RunLog",props:{run:Object},setup(e){const l=e,m=b(()=>{var r;return(((r=l.run)==null?void 0:r.log)||"").split(`
`).filter(Boolean)});return(r,j)=>e.run?(t(),n("div",{key:0,class:f(["rounded-xl border p-4",e.run.status==="error"?"border-red-200 bg-red-50":"border-ink-200 bg-white"])},[u("div",C,[e.run.status==="running"||e.run.status==="queued"?(t(),o(a(x),{key:0,class:"h-4 w-4 animate-spin text-ember-700"})):e.run.status==="done"?(t(),o(a(v),{key:1,class:"h-4 w-4 text-emerald-600"})):(t(),o(a(w),{key:2,class:"h-4 w-4 text-red-600"})),e.run.status==="queued"?(t(),n("span",p,"Na fila… (o worker da fila precisa estar rodando)")):e.run.status==="running"?(t(),n("span",B,"A IA está trabalhando")):e.run.status==="done"?(t(),n("span",q,"Concluído")):(t(),n("span",A,"Erro: "+s(e.run.error),1)),e.run.input_tokens||e.run.output_tokens?(t(),n("span",L,s(e.run.provider)+" · "+s(a(c)(e.run.input_tokens))+" in / "+s(a(c)(e.run.output_tokens))+" out ",1)):d("",!0)]),u("div",N,[(t(!0),n(y,null,g(m.value,(k,h)=>(t(),n("div",{key:h},s(k),1))),128))])],2)):d("",!0)}};export{v as C,w as T,E as _};
