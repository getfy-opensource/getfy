<<<<<<< HEAD:public/build/assets/srt-parser-DmWlEFZx.js
import{V as r,a as i,b as h}from"./prod-BoB9ETpF.js";import"./app-DDk75ra2.js";import"./runtime-dom.esm-bundler-OrSu0e66.js";import"./Toggle-BD3dflqy.js";const c=/,/g,a="-->";class n extends r{parse(s,e){if(s==="")this.c&&(this.l.push(this.c),this.h.onCue?.(this.c),this.c=null),this.e=i.None;else if(this.e===i.Cue)this.c.text+=(this.c.text?`
=======
import{V as r,a as i,b as h}from"./prod-BfczAo--.js";import"./app-CxU0cwBh.js";import"./runtime-dom.esm-bundler-OrSu0e66.js";import"./Toggle-BD3dflqy.js";const c=/,/g,a="-->";class n extends r{parse(s,e){if(s==="")this.c&&(this.l.push(this.c),this.h.onCue?.(this.c),this.c=null),this.e=i.None;else if(this.e===i.Cue)this.c.text+=(this.c.text?`
>>>>>>> 20e9d0ef1a67a6d1a7fab8c5028eb23f621c3628:public/build/assets/srt-parser-q00wLp9W.js
`:"")+s;else if(s.includes(a)){const t=this.q(s,e);t&&(this.c=new h(t[0],t[1],t[2].join(" ")),this.c.id=this.n,this.e=i.Cue)}this.n=s}q(s,e){return super.q(s.replace(c,"."),e)}}function l(){return new n}export{n as SRTParser,l as default};
