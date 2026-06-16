const __vite__mapDeps=(i,m=__vite__mapDeps,d=(m.f||(m.f=["assets/vidstack-BTigPj2h-BYwaDJQl.js","assets/vidstack-C2US-gSO-C_y7_0VX.js","assets/floating-ui.dom-RhtQlTMy.js"])))=>i.map(i=>d[i]);
import{n as b,p as ht,e as S,o as E,b as Qt,a6 as Oe,u as y,k as B,a7 as Ht,s as _,a8 as qt,a9 as g,aa as yt,a1 as Ut,g as Q,h as zt,ab as Be,ac as jt,ad as Yt,ae as Zt,af as X,j as q,ag as Ge,ah as Jt,r as Ne,ai as Ve,x as Fe,aj as Ee,J as bt,i as G,t as Re,C as Xt,d as te,ak as ee,al as We,a3 as gt,l as J,Z as Ke,am as Qe,an as He,ao as qe,ap as Ue,$ as se,a4 as _t}from"./vidstack-C2US-gSO-C_y7_0VX.js";import{a as ne}from"./app-DS9VnpnZ.js";import{A as N,T as ze,D as Vt,x as i,s as je,L as xt}from"./vidstack-CwTj4H1w-cyUztiKU.js";import"./floating-ui.dom-RhtQlTMy.js";import"./runtime-dom.esm-bundler-OrSu0e66.js";import"./Toggle-BD3dflqy.js";const Tt=e=>e??N;const tt={ATTRIBUTE:1,CHILD:2,BOOLEAN_ATTRIBUTE:4},U=e=>(...t)=>({_$litDirective$:e,values:t});let wt=class{constructor(t){}get _$AU(){return this._$AM._$AU}_$AT(t,s,n){this._$Ct=t,this._$AM=s,this._$Ci=n}_$AS(t,s){return this.update(t,s)}update(t,s){return this.render(...s)}};let et=class extends wt{constructor(t){if(super(t),this.et=N,t.type!==tt.CHILD)throw Error(this.constructor.directiveName+"() can only be used in child bindings")}render(t){if(t===N||t==null)return this.ft=void 0,this.et=t;if(t===ze)return t;if(typeof t!="string")throw Error(this.constructor.directiveName+"() called with a non-string value");if(t===this.et)return this.ft;this.et=t;const s=[t];return s.raw=s,this.ft={_$litType$:this.constructor.resultType,strings:s,values:[]}}};et.directiveName="unsafeHTML",et.resultType=1;const ae=U(et);class $t extends et{}$t.directiveName="unsafeSVG",$t.resultType=2;const Ye=U($t);const Ze=e=>e.strings===void 0,Je={},Xe=(e,t=Je)=>e._$AH=t;const H=(e,t)=>{var s,n;const a=e._$AN;if(a===void 0)return!1;for(const l of a)(n=(s=l)._$AO)===null||n===void 0||n.call(s,t,!1),H(l,t);return!0},st=e=>{let t,s;do{if((t=e._$AM)===void 0)break;s=t._$AN,s.delete(e),e=t}while(s?.size===0)},ie=e=>{for(let t;t=e._$AM;e=t){let s=t._$AN;if(s===void 0)t._$AN=s=new Set;else if(s.has(e))break;s.add(e),ss(t)}};function ts(e){this._$AN!==void 0?(st(this),this._$AM=e,ie(this)):this._$AM=e}function es(e,t=!1,s=0){const n=this._$AH,a=this._$AN;if(a!==void 0&&a.size!==0)if(t)if(Array.isArray(n))for(let l=s;l<n.length;l++)H(n[l],!1),st(n[l]);else n!=null&&(H(n,!1),st(n));else H(this,e)}const ss=e=>{var t,s,n,a;e.type==tt.CHILD&&((t=(n=e)._$AP)!==null&&t!==void 0||(n._$AP=es),(s=(a=e)._$AQ)!==null&&s!==void 0||(a._$AQ=ts))};class oe extends wt{constructor(){super(...arguments),this._$AN=void 0}_$AT(t,s,n){super._$AT(t,s,n),ie(this),this.isConnected=t._$AU}_$AO(t,s=!0){var n,a;t!==this.isConnected&&(this.isConnected=t,t?(n=this.reconnected)===null||n===void 0||n.call(this):(a=this.disconnected)===null||a===void 0||a.call(this)),s&&(H(this,t),st(this))}setValue(t){if(Ze(this._$Ct))this._$Ct._$AI(t,this);else{const s=[...this._$Ct._$AH];s[this._$Ci]=t,this._$Ct._$AI(s,this,0)}}disconnected(){}reconnected(){}}class ns extends oe{#t=null;#e=!1;#s=null;constructor(t){super(t),this.#e=t.type===tt.ATTRIBUTE||t.type===tt.BOOLEAN_ATTRIBUTE}render(t){return t!==this.#t&&(this.disconnected(),this.#t=t,this.isConnected&&this.#n()),this.#t?this.#a(ht(this.#t)):N}reconnected(){this.#n()}disconnected(){this.#s?.(),this.#s=null}#n(){this.#t&&(this.#s=S(this.#o.bind(this)))}#a(t){return this.#e?Tt(t):t}#i(t){this.setValue(this.#a(t))}#o(){this.#i(this.#t?.())}}function o(e){return U(ns)(b(e))}class le{#t;#e;elements=new Set;constructor(t,s){this.#t=t,this.#e=s}connect(){this.#n();const t=new MutationObserver(this.#s);for(const s of this.#t)t.observe(s,{childList:!0,subtree:!0});E(()=>t.disconnect()),E(this.disconnect.bind(this))}disconnect(){this.elements.clear()}assign(t,s){Oe(t)?(s.textContent="",s.append(t)):(Vt(null,s),Vt(t,s)),s.style.display||(s.style.display="contents");const n=s.firstElementChild;if(!n)return;const a=s.getAttribute("data-class");a&&n.classList.add(...a.split(" "))}#s=Qt(this.#n.bind(this));#n(t){if(t&&!t.some(a=>a.addedNodes.length))return;let s=!1,n=this.#t.flatMap(a=>[...a.querySelectorAll("slot")]);for(const a of n)!a.hasAttribute("name")||this.elements.has(a)||(this.elements.add(a),s=!0);s&&this.#e(this.elements)}}let as=0,Z="data-slot-id";class kt{#t;slots;constructor(t){this.#t=t,this.slots=new le(t,this.#s.bind(this))}connect(){this.slots.connect(),this.#s();const t=new MutationObserver(this.#e);for(const s of this.#t)t.observe(s,{childList:!0});E(()=>t.disconnect())}#e=Qt(this.#s.bind(this));#s(){for(const t of this.#t)for(const s of t.children){if(s.nodeType!==1)continue;const n=s.getAttribute("slot");if(!n)continue;s.style.display="none";let a=s.getAttribute(Z);a||s.setAttribute(Z,a=++as+"");for(const l of this.slots.elements){if(l.getAttribute("name")!==n||l.getAttribute(Z)===a)continue;const r=document.importNode(s,!0);n.includes("-icon")&&r.classList.add("vds-icon"),r.style.display="",r.removeAttribute("slot"),this.slots.assign(r,l),l.setAttribute(Z,a)}}}}function re({name:e,class:t,state:s,paths:n,viewBox:a="0 0 32 32"}){return i`<svg
    class="${"vds-icon"+(t?` ${t}`:"")}"
    viewBox="${a}"
    fill="none"
    aria-hidden="true"
    focusable="false"
    xmlns="http://www.w3.org/2000/svg"
    data-icon=${Tt(e??s)}
  >
    ${B(n)?Ye(n):o(n)}
  </svg>`}class is{#t={};#e=!1;slots;constructor(t){this.slots=new le(t,this.#n.bind(this))}connect(){this.slots.connect()}load(){this.loadIcons().then(t=>{this.#t=t,this.#e=!0,this.#n()})}*#s(){for(const t of Object.keys(this.#t)){const s=`${t}-icon`;for(const n of this.slots.elements)n.name===s&&(yield{icon:this.#t[t],slot:n})}}#n(){if(this.#e)for(const{icon:t,slot:s}of this.#s())this.slots.assign(t,s)}}class ue extends is{connect(){super.connect();const{player:t}=y();if(!t.el)return;let s,n=new IntersectionObserver(a=>{a[0]?.isIntersecting&&(s?.(),s=void 0,this.load())});n.observe(t.el),s=E(()=>n.disconnect())}}const mt=new WeakMap,at=U(class extends oe{render(e){return N}update(e,[t]){var s;const n=t!==this.G;return n&&this.G!==void 0&&this.ot(void 0),(n||this.rt!==this.lt)&&(this.G=t,this.dt=(s=e.options)===null||s===void 0?void 0:s.host,this.ot(this.lt=e.element)),N}ot(e){var t;if(typeof this.G=="function"){const s=(t=this.dt)!==null&&t!==void 0?t:globalThis;let n=mt.get(s);n===void 0&&(n=new WeakMap,mt.set(s,n)),n.get(this.G)!==void 0&&this.G.call(this.dt,void 0),n.set(this.G,e),e!==void 0&&this.G.call(this.dt,e)}else this.G.value=e}get rt(){var e,t,s;return typeof this.G=="function"?(t=mt.get((e=this.dt)!==null&&e!==void 0?e:globalThis))===null||t===void 0?void 0:t.get(this.G):(s=this.G)===null||s===void 0?void 0:s.value}disconnected(){this.rt===this.lt&&this.ot(void 0)}reconnected(){this.ot(this.lt)}}),de=Jt();function d(){return Ht(de)}const os={colorScheme:"system",download:null,customIcons:!1,disableTimeSlider:!1,menuContainer:null,menuGroup:"bottom",noAudioGain:!1,noGestures:!1,noKeyboardAnimations:!1,noModal:!1,noScrubGesture:!1,playbackRates:{min:0,max:2,step:.25},audioGains:{min:0,max:300,step:25},seekStep:10,sliderChaptersMinWidth:325,hideQualityBitrate:!1,smallWhen:!1,thumbnails:null,translations:null,when:!1};class St extends Xt{static props=os;#t;#e=b(()=>{const t=this.$props.when();return this.#n(t)});#s=b(()=>{const t=this.$props.smallWhen();return this.#n(t)});get isMatch(){return this.#e()}get isSmallLayout(){return this.#s()}onSetup(){this.#t=y(),this.setAttributes({"data-match":this.#e,"data-sm":()=>this.#s()?"":null,"data-lg":()=>this.#s()?null:"","data-size":()=>this.#s()?"sm":"lg","data-no-scrub-gesture":this.$props.noScrubGesture}),te(de,{...this.$props,when:this.#e,smallWhen:this.#s,userPrefersAnnouncements:_(!0),userPrefersKeyboardAnimations:_(!0),menuPortal:_(null)})}onAttach(t){ee(t,this.$props.colorScheme)}#n(t){return t!=="never"&&(We(t)?t:b(()=>t(this.#t.player.state))())}}const ce=St.prototype;Ut(ce,"isMatch");Ut(ce,"isSmallLayout");function pe(e,t){S(()=>{const{player:s}=y(),n=s.el;return n&&Q(n,"data-layout",t()&&e),()=>n?.removeAttribute("data-layout")})}function C(e,t){return e()?.[t]??t}function Ct(){return o(()=>{const{translations:e,userPrefersAnnouncements:t}=d();return t()?i`<media-announcer .translations=${o(e)}></media-announcer>`:null})}function A(e,t=""){return i`<slot
    name=${`${e}-icon`}
    data-class=${`vds-icon vds-${e}-icon${t?` ${t}`:""}`}
  ></slot>`}function z(e){return e.map(t=>A(t))}function c(e,t){return o(()=>C(e,t))}function At({tooltip:e}){const{translations:t}=d(),{remotePlaybackState:s}=g(),n=o(()=>{const l=C(t,"AirPlay"),r=zt(s());return`${l} ${r}`}),a=c(t,"AirPlay");return i`
    <media-tooltip class="vds-airplay-tooltip vds-tooltip">
      <media-tooltip-trigger>
        <media-airplay-button class="vds-airplay-button vds-button" aria-label=${n}>
          ${A("airplay")}
        </media-airplay-button>
      </media-tooltip-trigger>
      <media-tooltip-content class="vds-tooltip-content" placement=${e}>
        <span class="vds-airplay-tooltip-text">${a}</span>
      </media-tooltip-content>
    </media-tooltip>
  `}function me({tooltip:e}){const{translations:t}=d(),{remotePlaybackState:s}=g(),n=o(()=>{const l=C(t,"Google Cast"),r=zt(s());return`${l} ${r}`}),a=c(t,"Google Cast");return i`
    <media-tooltip class="vds-google-cast-tooltip vds-tooltip">
      <media-tooltip-trigger>
        <media-google-cast-button class="vds-google-cast-button vds-button" aria-label=${n}>
          ${A("google-cast")}
        </media-google-cast-button>
      </media-tooltip-trigger>
      <media-tooltip-content class="vds-tooltip-content" placement=${e}>
        <span class="vds-google-cast-tooltip-text">${a}</span>
      </media-tooltip-content>
    </media-tooltip>
  `}function it({tooltip:e}){const{translations:t}=d(),s=c(t,"Play"),n=c(t,"Pause");return i`
    <media-tooltip class="vds-play-tooltip vds-tooltip">
      <media-tooltip-trigger>
        <media-play-button
          class="vds-play-button vds-button"
          aria-label=${c(t,"Play")}
        >
          ${z(["play","pause","replay"])}
        </media-play-button>
      </media-tooltip-trigger>
      <media-tooltip-content class="vds-tooltip-content" placement=${e}>
        <span class="vds-play-tooltip-text">${s}</span>
        <span class="vds-pause-tooltip-text">${n}</span>
      </media-tooltip-content>
    </media-tooltip>
  `}function Ft({tooltip:e,ref:t=Fe}){const{translations:s}=d(),n=c(s,"Mute"),a=c(s,"Unmute");return i`
    <media-tooltip class="vds-mute-tooltip vds-tooltip">
      <media-tooltip-trigger>
        <media-mute-button
          class="vds-mute-button vds-button"
          aria-label=${c(s,"Mute")}
          ${at(t)}
        >
          ${z(["mute","volume-low","volume-high"])}
        </media-mute-button>
      </media-tooltip-trigger>
      <media-tooltip-content class="vds-tooltip-content" placement=${e}>
        <span class="vds-mute-tooltip-text">${a}</span>
        <span class="vds-unmute-tooltip-text">${n}</span>
      </media-tooltip-content>
    </media-tooltip>
  `}function Dt({tooltip:e}){const{translations:t}=d(),s=c(t,"Closed-Captions On"),n=c(t,"Closed-Captions Off");return i`
    <media-tooltip class="vds-caption-tooltip vds-tooltip">
      <media-tooltip-trigger>
        <media-caption-button
          class="vds-caption-button vds-button"
          aria-label=${c(t,"Captions")}
        >
          ${z(["cc-on","cc-off"])}
        </media-caption-button>
      </media-tooltip-trigger>
      <media-tooltip-content class="vds-tooltip-content" placement=${e}>
        <span class="vds-cc-on-tooltip-text">${n}</span>
        <span class="vds-cc-off-tooltip-text">${s}</span>
      </media-tooltip-content>
    </media-tooltip>
  `}function ls(){const{translations:e}=d(),t=c(e,"Enter PiP"),s=c(e,"Exit PiP");return i`
    <media-tooltip class="vds-pip-tooltip vds-tooltip">
      <media-tooltip-trigger>
        <media-pip-button
          class="vds-pip-button vds-button"
          aria-label=${c(e,"PiP")}
        >
          ${z(["pip-enter","pip-exit"])}
        </media-pip-button>
      </media-tooltip-trigger>
      <media-tooltip-content class="vds-tooltip-content">
        <span class="vds-pip-enter-tooltip-text">${t}</span>
        <span class="vds-pip-exit-tooltip-text">${s}</span>
      </media-tooltip-content>
    </media-tooltip>
  `}function ve({tooltip:e}){const{translations:t}=d(),s=c(t,"Enter Fullscreen"),n=c(t,"Exit Fullscreen");return i`
    <media-tooltip class="vds-fullscreen-tooltip vds-tooltip">
      <media-tooltip-trigger>
        <media-fullscreen-button
          class="vds-fullscreen-button vds-button"
          aria-label=${c(t,"Fullscreen")}
        >
          ${z(["fs-enter","fs-exit"])}
        </media-fullscreen-button>
      </media-tooltip-trigger>
      <media-tooltip-content class="vds-tooltip-content" placement=${e}>
        <span class="vds-fs-enter-tooltip-text">${s}</span>
        <span class="vds-fs-exit-tooltip-text">${n}</span>
      </media-tooltip-content>
    </media-tooltip>
  `}function Et({backward:e,tooltip:t}){const{translations:s,seekStep:n}=d(),a=e?"Seek Backward":"Seek Forward",l=c(s,a);return i`
    <media-tooltip class="vds-seek-tooltip vds-tooltip">
      <media-tooltip-trigger>
        <media-seek-button
          class="vds-seek-button vds-button"
          seconds=${o(()=>(e?-1:1)*n())}
          aria-label=${l}
        >
          ${A(e?"seek-backward":"seek-forward")}
        </media-seek-button>
      </media-tooltip-trigger>
      <media-tooltip-content class="vds-tooltip-content" placement=${t}>
        ${c(s,a)}
      </media-tooltip-content>
    </media-tooltip>
  `}function fe(){const{translations:e}=d(),{live:t}=g(),s=c(e,"Skip To Live"),n=c(e,"LIVE");return t()?i`
        <media-live-button class="vds-live-button" aria-label=${s}>
          <span class="vds-live-button-text">${n}</span>
        </media-live-button>
      `:null}function Mt(){return o(()=>{const{download:e,translations:t}=d(),s=e();if(Be(s))return null;const{source:n,title:a}=g(),l=n(),r=jt({title:a(),src:l,download:s});return B(r?.url)?i`
          <media-tooltip class="vds-download-tooltip vds-tooltip">
            <media-tooltip-trigger>
              <a
                role="button"
                class="vds-download-button vds-button"
                aria-label=${c(t,"Download")}
                href=${Yt(r.url,{download:r.name})}
                download=${r.name}
                target="_blank"
              >
                <slot name="download-icon" data-class="vds-icon" />
              </a>
            </media-tooltip-trigger>
            <media-tooltip-content class="vds-tooltip-content" placement="top">
              ${c(t,"Download")}
            </media-tooltip-content>
          </media-tooltip>
        `:null})}function Pt(){const{translations:e}=d();return i`
    <media-captions
      class="vds-captions"
      .exampleText=${c(e,"Captions look like this")}
    ></media-captions>
  `}function I(){return i`<div class="vds-controls-spacer"></div>`}function $e(e,t){return i`
    <media-menu-portal .container=${o(e)} disabled="fullscreen">
      ${t}
    </media-menu-portal>
  `}function he(e,t,s,n){let a=B(t)?document.querySelector(t):t;a||(a=e?.closest("dialog")),a||(a=document.body);const l=document.createElement("div");l.style.display="contents",l.classList.add(s),a.append(l),S(()=>{if(!l)return;const{viewType:p}=g(),u=n();Q(l,"data-view-type",p()),Q(l,"data-sm",u),Q(l,"data-lg",!u),Q(l,"data-size",u?"sm":"lg")});const{colorScheme:r}=d();return ee(l,r),l}function ye({placement:e,tooltip:t,portal:s}){const{textTracks:n}=y(),{viewType:a,seekableStart:l,seekableEnd:r}=g(),{translations:p,thumbnails:u,menuPortal:v,noModal:f,menuGroup:m,smallWhen:x}=d();if(b(()=>{const P=l(),W=r(),K=_(null);return Zt(n,"chapters",K.set),!K()?.cues.filter(Nt=>Nt.startTime<=W&&Nt.endTime>=P)?.length})())return null;const w=b(()=>f()?X(e):x()?null:X(e)),M=b(()=>!x()&&m()==="bottom"&&a()==="video"?26:0),k=_(!1);function O(){k.set(!0)}function Y(){k.set(!1)}const R=i`
    <media-menu-items
      class="vds-chapters-menu-items vds-menu-items"
      placement=${o(w)}
      offset=${o(M)}
    >
      ${o(()=>k()?i`
          <media-chapters-radio-group
            class="vds-chapters-radio-group vds-radio-group"
            .thumbnails=${o(u)}
          >
            <template>
              <media-radio class="vds-chapter-radio vds-radio">
                <media-thumbnail class="vds-thumbnail"></media-thumbnail>
                <div class="vds-chapter-radio-content">
                  <span class="vds-chapter-radio-label" data-part="label"></span>
                  <span class="vds-chapter-radio-start-time" data-part="start-time"></span>
                  <span class="vds-chapter-radio-duration" data-part="duration"></span>
                </div>
              </media-radio>
            </template>
          </media-chapters-radio-group>
        `:null)}
    </media-menu-items>
  `;return i`
    <media-menu class="vds-chapters-menu vds-menu" @open=${O} @close=${Y}>
      <media-tooltip class="vds-tooltip">
        <media-tooltip-trigger>
          <media-menu-button
            class="vds-menu-button vds-button"
            aria-label=${c(p,"Chapters")}
          >
            ${A("menu-chapters")}
          </media-menu-button>
        </media-tooltip-trigger>
        <media-tooltip-content
          class="vds-tooltip-content"
          placement=${yt(t)?o(t):t}
        >
          ${c(p,"Chapters")}
        </media-tooltip-content>
      </media-tooltip>
      ${$e(v,R)}
    </media-menu>
  `}function vt(e){const{style:t}=new Option;return t.color=e,t.color.match(/\((.*?)\)/)[1].replace(/,/g," ")}const It={type:"color"},rs={type:"radio",values:{"Monospaced Serif":"mono-serif","Proportional Serif":"pro-serif","Monospaced Sans-Serif":"mono-sans","Proportional Sans-Serif":"pro-sans",Casual:"casual",Cursive:"cursive","Small Capitals":"capitals"}},us={type:"slider",min:0,max:400,step:25,upIcon:null,downIcon:null},ds={type:"slider",min:0,max:100,step:5,upIcon:null,downIcon:null},cs={type:"radio",values:["None","Drop Shadow","Raised","Depressed","Outline"]},nt={fontFamily:"pro-sans",fontSize:"100%",textColor:"#ffffff",textOpacity:"100%",textShadow:"none",textBg:"#000000",textBgOpacity:"100%",displayBg:"#000000",displayBgOpacity:"0%"},V=Object.keys(nt).reduce((e,t)=>({...e,[t]:_(nt[t])}),{});for(const e of Object.keys(V)){const t=localStorage.getItem(`vds-player:${q(e)}`);B(t)&&V[e].set(t)}function ps(){for(const e of Object.keys(V)){const t=nt[e];V[e].set(t)}}let Rt=!1,ft=new Set;function ms(){const{player:e}=y();ft.add(e),E(()=>ft.delete(e)),Rt||(Ne(()=>{for(const t of Ve(V)){const s=V[t],n=nt[t],a=`--media-user-${q(t)}`,l=`vds-player:${q(t)}`;S(()=>{const r=s(),p=r===n,u=p?null:vs(e,t,r);for(const v of ft)v.el?.style.setProperty(a,u);p?localStorage.removeItem(l):localStorage.setItem(l,r)})}},null),Rt=!0)}function vs(e,t,s){switch(t){case"fontFamily":const n=s==="capitals"?"small-caps":"";return e.el?.style.setProperty("--media-user-font-variant",n),$s(s);case"fontSize":case"textOpacity":case"textBgOpacity":case"displayBgOpacity":return fs(s);case"textColor":return`rgb(${vt(s)} / var(--media-user-text-opacity, 1))`;case"textShadow":return hs(s);case"textBg":return`rgb(${vt(s)} / var(--media-user-text-bg-opacity, 1))`;case"displayBg":return`rgb(${vt(s)} / var(--media-user-display-bg-opacity, 1))`}}function fs(e){return(parseInt(e)/100).toString()}function $s(e){switch(e){case"mono-serif":return'"Courier New", Courier, "Nimbus Mono L", "Cutive Mono", monospace';case"mono-sans":return'"Deja Vu Sans Mono", "Lucida Console", Monaco, Consolas, "PT Mono", monospace';case"pro-sans":return'Roboto, "Arial Unicode Ms", Arial, Helvetica, Verdana, "PT Sans Caption", sans-serif';case"casual":return'"Comic Sans MS", Impact, Handlee, fantasy';case"cursive":return'"Monotype Corsiva", "URW Chancery L", "Apple Chancery", "Dancing Script", cursive';case"capitals":return'"Arial Unicode Ms", Arial, Helvetica, Verdana, "Marcellus SC", sans-serif + font-variant=small-caps';default:return'"Times New Roman", Times, Georgia, Cambria, "PT Serif Caption", serif'}}function hs(e){switch(e){case"drop shadow":return"rgb(34, 34, 34) 1.86389px 1.86389px 2.79583px, rgb(34, 34, 34) 1.86389px 1.86389px 3.72778px, rgb(34, 34, 34) 1.86389px 1.86389px 4.65972px";case"raised":return"rgb(34, 34, 34) 1px 1px, rgb(34, 34, 34) 2px 2px";case"depressed":return"rgb(204, 204, 204) 1px 1px, rgb(34, 34, 34) -1px -1px";case"outline":return"rgb(34, 34, 34) 0px 0px 1.86389px, rgb(34, 34, 34) 0px 0px 1.86389px, rgb(34, 34, 34) 0px 0px 1.86389px, rgb(34, 34, 34) 0px 0px 1.86389px, rgb(34, 34, 34) 0px 0px 1.86389px";default:return""}}let ys=0;function D({label:e="",value:t="",children:s}){if(!e)return i`
      <div class="vds-menu-section">
        <div class="vds-menu-section-body">${s}</div>
      </div>
    `;const n=`vds-menu-section-${++ys}`;return i`
    <section class="vds-menu-section" role="group" aria-labelledby=${n}>
      <div class="vds-menu-section-title">
        <header id=${n}>${e}</header>
        ${t?i`<div class="vds-menu-section-value">${t}</div>`:null}
      </div>
      <div class="vds-menu-section-body">${s}</div>
    </section>
  `}function j({label:e,children:t}){return i`
    <div class="vds-menu-item">
      <div class="vds-menu-item-label">${e}</div>
      ${t}
    </div>
  `}function F({label:e,icon:t,hint:s}){return i`
    <media-menu-button class="vds-menu-item">
      ${A("menu-arrow-left","vds-menu-close-icon")}
      ${t?A(t,"vds-menu-item-icon"):null}
      <span class="vds-menu-item-label">${o(e)}</span>
      <span class="vds-menu-item-hint" data-part="hint">${s?o(s):null} </span>
      ${A("menu-arrow-right","vds-menu-open-icon")}
    </media-menu-button>
  `}function bs({value:e=null,options:t,hideLabel:s=!1,children:n=null,onChange:a=null}){function l(r){const{value:p,label:u}=r;return i`
      <media-radio class="vds-radio" value=${p}>
        ${A("menu-radio-check")}
        ${s?null:i`
              <span class="vds-radio-label" data-part="label">
                ${B(u)?u:o(u)}
              </span>
            `}
        ${yt(n)?n(r):n}
      </media-radio>
    `}return i`
    <media-radio-group
      class="vds-radio-group"
      value=${B(e)?e:e?o(e):""}
      @change=${a}
    >
      ${G(t)?t.map(l):o(()=>t().map(l))}
    </media-radio-group>
  `}function gs(e){return G(e)?e.map(t=>({label:t,value:t.toLowerCase()})):Object.keys(e).map(t=>({label:t,value:e[t]}))}function ot(){return i`
    <div class="vds-slider-track"></div>
    <div class="vds-slider-track-fill vds-slider-track"></div>
    <div class="vds-slider-thumb"></div>
  `}function lt(){return i`
    <media-slider-steps class="vds-slider-steps">
      <template>
        <div class="vds-slider-step"></div>
      </template>
    </media-slider-steps>
  `}function rt({label:e=null,value:t=null,upIcon:s="",downIcon:n="",children:a,isMin:l,isMax:r}){const p=e||t,u=[n?A(n,"down"):null,a,s?A(s,"up"):null];return i`
    <div
      class=${`vds-menu-item vds-menu-slider-item${p?" group":""}`}
      data-min=${o(()=>l()?"":null)}
      data-max=${o(()=>r()?"":null)}
    >
      ${p?i`
            <div class="vds-menu-slider-title">
              ${[e?i`<div>${e}</div>`:null,t?i`<div>${t}</div>`:null]}
            </div>
            <div class="vds-menu-slider-body">${u}</div>
          `:u}
    </div>
  `}const _s={...us,upIcon:"menu-opacity-up",downIcon:"menu-opacity-down"},Lt={...ds,upIcon:"menu-opacity-up",downIcon:"menu-opacity-down"};function xs(){return o(()=>{const{hasCaptions:e}=g(),{translations:t}=d();return e()?i`
      <media-menu class="vds-font-menu vds-menu">
        ${F({label:()=>C(t,"Caption Styles")})}
        <media-menu-items class="vds-menu-items">
          ${[D({label:c(t,"Font"),children:[Ts(),ws()]}),D({label:c(t,"Text"),children:[ks(),Cs(),Ss()]}),D({label:c(t,"Text Background"),children:[As(),Ds()]}),D({label:c(t,"Display Background"),children:[Ms(),Ps()]}),D({children:[Is()]})]}
        </media-menu-items>
      </media-menu>
    `:null})}function Ts(){return L({label:"Family",option:rs,type:"fontFamily"})}function ws(){return L({label:"Size",option:_s,type:"fontSize"})}function ks(){return L({label:"Color",option:It,type:"textColor"})}function Ss(){return L({label:"Opacity",option:Lt,type:"textOpacity"})}function Cs(){return L({label:"Shadow",option:cs,type:"textShadow"})}function As(){return L({label:"Color",option:It,type:"textBg"})}function Ds(){return L({label:"Opacity",option:Lt,type:"textBgOpacity"})}function Ms(){return L({label:"Color",option:It,type:"displayBg"})}function Ps(){return L({label:"Opacity",option:Lt,type:"displayBgOpacity"})}function Is(){const{translations:e}=d();return i`
    <button class="vds-menu-item" role="menuitem" @click=${ps}>
      <span class="vds-menu-item-label">${o(()=>C(e,"Reset"))}</span>
    </button>
  `}function L({label:e,option:t,type:s}){const{player:n}=y(),{translations:a}=d(),l=V[s],r=()=>C(a,e);function p(){Re(),n.dispatchEvent(new Event("vds-font-change"))}if(t.type==="color"){let f=function(m){l.set(m.target.value),p()};return j({label:o(r),children:i`
        <input
          class="vds-color-picker"
          type="color"
          .value=${o(l)}
          @input=${f}
        />
      `})}if(t.type==="slider"){let f=function(k){l.set(k.detail+"%"),p()};const{min:m,max:x,step:T,upIcon:w,downIcon:M}=t;return rt({label:o(r),value:o(l),upIcon:w,downIcon:M,isMin:()=>l()===m+"%",isMax:()=>l()===x+"%",children:i`
        <media-slider
          class="vds-slider"
          min=${m}
          max=${x}
          step=${T}
          key-step=${T}
          .value=${o(()=>parseInt(l()))}
          aria-label=${o(r)}
          @value-change=${f}
          @drag-value-change=${f}
        >
          ${ot()}${lt()}
        </media-slider>
      `})}const u=gs(t.values),v=()=>{const f=l(),m=u.find(x=>x.value===f)?.label||"";return C(a,B(m)?m:m())};return i`
    <media-menu class=${`vds-${q(s)}-menu vds-menu`}>
      ${F({label:r,hint:v})}
      <media-menu-items class="vds-menu-items">
        ${bs({value:l,options:u,onChange({detail:f}){l.set(f),p()}})}
      </media-menu-items>
    </media-menu>
  `}function ut({label:e,checked:t,defaultChecked:s=!1,storageKey:n,onChange:a}){const{translations:l}=d(),r=n?localStorage.getItem(n):null,p=_(!!(r??s)),u=_(!1),v=o(Ee(p)),f=c(l,e);n&&a(ht(p)),t&&S(()=>{p.set(t())});function m(w){w?.button!==1&&(p.set(M=>!M),n&&localStorage.setItem(n,p()?"1":""),a(p(),w),u.set(!1))}function x(w){bt(w)&&m()}function T(w){w.button===0&&u.set(!0)}return i`
    <div
      class="vds-menu-checkbox"
      role="menuitemcheckbox"
      tabindex="0"
      aria-label=${f}
      aria-checked=${v}
      data-active=${o(()=>u()?"":null)}
      @pointerup=${m}
      @pointerdown=${T}
      @keydown=${x}
    ></div>
  `}function Ls(){return o(()=>{const{translations:e}=d();return i`
      <media-menu class="vds-accessibility-menu vds-menu">
        ${F({label:()=>C(e,"Accessibility"),icon:"menu-accessibility"})}
        <media-menu-items class="vds-menu-items">
          ${[D({children:[Os(),Bs()]}),D({children:[xs()]})]}
        </media-menu-items>
      </media-menu>
    `})}function Os(){const{userPrefersAnnouncements:e,translations:t}=d(),s="Announcements";return j({label:c(t,s),children:ut({label:s,storageKey:"vds-player::announcements",onChange(n){e.set(n)}})})}function Bs(){return o(()=>{const{translations:e,userPrefersKeyboardAnimations:t,noKeyboardAnimations:s}=d(),{viewType:n}=g();if(b(()=>n()!=="video"||s())())return null;const l="Keyboard Animations";return j({label:c(e,l),children:ut({label:l,defaultChecked:!0,storageKey:"vds-player::keyboard-animations",onChange(r){t.set(r)}})})})}function Gs(){return o(()=>{const{noAudioGain:e,translations:t}=d(),{audioTracks:s,canSetAudioGain:n}=g();return b(()=>!(n()&&!e())&&s().length<=1)()?null:i`
      <media-menu class="vds-audio-menu vds-menu">
        ${F({label:()=>C(t,"Audio"),icon:"menu-audio"})}
        <media-menu-items class="vds-menu-items">
          ${[Ns(),Vs()]}
        </media-menu-items>
      </media-menu>
    `})}function Ns(){return o(()=>{const{translations:e}=d(),{audioTracks:t}=g(),s=c(e,"Default");return b(()=>t().length<=1)()?null:D({children:i`
        <media-menu class="vds-audio-tracks-menu vds-menu">
          ${F({label:()=>C(e,"Track")})}
          <media-menu-items class="vds-menu-items">
            <media-audio-radio-group
              class="vds-audio-track-radio-group vds-radio-group"
              empty-label=${s}
            >
              <template>
                <media-radio class="vds-audio-track-radio vds-radio">
                  <slot name="menu-radio-check-icon" data-class="vds-icon"></slot>
                  <span class="vds-radio-label" data-part="label"></span>
                </media-radio>
              </template>
            </media-audio-radio-group>
          </media-menu-items>
        </media-menu>
      `})})}function Vs(){return o(()=>{const{noAudioGain:e,translations:t}=d(),{canSetAudioGain:s}=g();if(b(()=>!s()||e())())return null;const{audioGain:a}=g();return D({label:c(t,"Boost"),value:o(()=>Math.round(((a()??1)-1)*100)+"%"),children:[rt({upIcon:"menu-audio-boost-up",downIcon:"menu-audio-boost-down",children:Fs(),isMin:()=>((a()??1)-1)*100<=be(),isMax:()=>((a()??1)-1)*100===ge()})]})})}function Fs(){const{translations:e}=d(),t=c(e,"Boost"),s=be,n=ge,a=Es;return i`
    <media-audio-gain-slider
      class="vds-audio-gain-slider vds-slider"
      aria-label=${t}
      min=${o(s)}
      max=${o(n)}
      step=${o(a)}
      key-step=${o(a)}
    >
      ${ot()}${lt()}
    </media-audio-gain-slider>
  `}function be(){const{audioGains:e}=d(),t=e();return G(t)?t[0]??0:t.min}function ge(){const{audioGains:e}=d(),t=e();return G(t)?t[t.length-1]??300:t.max}function Es(){const{audioGains:e}=d(),t=e();return G(t)?t[1]-t[0]||25:t.step}function Rs(){return o(()=>{const{translations:e}=d(),{hasCaptions:t}=g(),s=c(e,"Off");return t()?i`
      <media-menu class="vds-captions-menu vds-menu">
        ${F({label:()=>C(e,"Captions"),icon:"menu-captions"})}
        <media-menu-items class="vds-menu-items">
          <media-captions-radio-group
            class="vds-captions-radio-group vds-radio-group"
            off-label=${s}
          >
            <template>
              <media-radio class="vds-caption-radio vds-radio">
                <slot name="menu-radio-check-icon" data-class="vds-icon"></slot>
                <span class="vds-radio-label" data-part="label"></span>
              </media-radio>
            </template>
          </media-captions-radio-group>
        </media-menu-items>
      </media-menu>
    `:null})}function Ws(){return o(()=>{const{translations:e}=d();return i`
      <media-menu class="vds-playback-menu vds-menu">
        ${F({label:()=>C(e,"Playback"),icon:"menu-playback"})}
        <media-menu-items class="vds-menu-items">
          ${[D({children:Ks()}),Qs(),zs()]}
        </media-menu-items>
      </media-menu>
    `})}function Ks(){const{remote:e}=y(),{translations:t}=d(),s="Loop";return j({label:c(t,s),children:ut({label:s,storageKey:"vds-player::user-loop",onChange(n,a){e.userPrefersLoopChange(n,a)}})})}function Qs(){return o(()=>{const{translations:e}=d(),{canSetPlaybackRate:t,playbackRate:s}=g();return t()?D({label:c(e,"Speed"),value:o(()=>s()===1?C(e,"Normal"):s()+"x"),children:[rt({upIcon:"menu-speed-up",downIcon:"menu-speed-down",children:qs(),isMin:()=>s()===_e(),isMax:()=>s()===xe()})]}):null})}function _e(){const{playbackRates:e}=d(),t=e();return G(t)?t[0]??0:t.min}function xe(){const{playbackRates:e}=d(),t=e();return G(t)?t[t.length-1]??2:t.max}function Hs(){const{playbackRates:e}=d(),t=e();return G(t)?t[1]-t[0]||.25:t.step}function qs(){const{translations:e}=d(),t=c(e,"Speed"),s=_e,n=xe,a=Hs;return i`
    <media-speed-slider
      class="vds-speed-slider vds-slider"
      aria-label=${t}
      min=${o(s)}
      max=${o(n)}
      step=${o(a)}
      key-step=${o(a)}
    >
      ${ot()}${lt()}
    </media-speed-slider>
  `}function Us(){const{remote:e,qualities:t}=y(),{autoQuality:s,canSetQuality:n,qualities:a}=g(),{translations:l}=d(),r="Auto";return b(()=>!n()||a().length<=1)()?null:j({label:c(l,r),children:ut({label:r,checked:s,onChange(u,v){u?e.requestAutoQuality(v):e.changeQuality(t.selectedIndex,v)}})})}function zs(){return o(()=>{const{hideQualityBitrate:e,translations:t}=d(),{canSetQuality:s,qualities:n,quality:a}=g(),l=b(()=>!s()||n().length<=1),r=b(()=>je(n()));return l()?null:D({label:c(t,"Quality"),value:o(()=>{const p=a()?.height,u=e()?null:a()?.bitrate,v=u&&u>0?`${(u/1e6).toFixed(2)} Mbps`:null,f=C(t,"Auto");return p?`${p}p${v?` (${v})`:""}`:f}),children:[rt({upIcon:"menu-quality-up",downIcon:"menu-quality-down",children:js(),isMin:()=>r()[0]===a(),isMax:()=>r().at(-1)===a()}),Us()]})})}function js(){const{translations:e}=d(),t=c(e,"Quality");return i`
    <media-quality-slider class="vds-quality-slider vds-slider" aria-label=${t}>
      ${ot()}${lt()}
    </media-quality-slider>
  `}function Te({placement:e,portal:t,tooltip:s}){return o(()=>{const{viewType:n}=g(),{translations:a,menuPortal:l,noModal:r,menuGroup:p,smallWhen:u}=d(),v=b(()=>r()?X(e):u()?null:X(e)),f=b(()=>!u()&&p()==="bottom"&&n()==="video"?26:0),m=_(!1);ms();function x(){m.set(!0)}function T(){m.set(!1)}const w=i`
      <media-menu-items
        class="vds-settings-menu-items vds-menu-items"
        placement=${o(v)}
        offset=${o(f)}
      >
        ${o(()=>m()?[Ws(),Ls(),Gs(),Rs()]:null)}
      </media-menu-items>
    `;return i`
      <media-menu class="vds-settings-menu vds-menu" @open=${x} @close=${T}>
        <media-tooltip class="vds-tooltip">
          <media-tooltip-trigger>
            <media-menu-button
              class="vds-menu-button vds-button"
              aria-label=${c(a,"Settings")}
            >
              ${A("menu-settings","vds-rotate-icon")}
            </media-menu-button>
          </media-tooltip-trigger>
          <media-tooltip-content
            class="vds-tooltip-content"
            placement=${yt(s)?o(s):s}
          >
            ${c(a,"Settings")}
          </media-tooltip-content>
        </media-tooltip>
        ${$e(l,w)}
      </media-menu>
    `})}function Ot({orientation:e,tooltip:t}){return o(()=>{const{pointer:s,muted:n,canSetVolume:a}=g();if(s()==="coarse"&&!n())return null;if(!a())return Ft({tooltip:t});const l=_(void 0),r=Ge(l);return i`
      <div class="vds-volume" ?data-active=${o(r)} ${at(l.set)}>
        ${Ft({tooltip:t})}
        <div class="vds-volume-popup">${Ys({orientation:e})}</div>
      </div>
    `})}function Ys({orientation:e}={}){const{translations:t}=d(),s=c(t,"Volume");return i`
    <media-volume-slider
      class="vds-volume-slider vds-slider"
      aria-label=${s}
      orientation=${Tt(e)}
    >
      <div class="vds-slider-track"></div>
      <div class="vds-slider-track-fill vds-slider-track"></div>
      <media-slider-preview class="vds-slider-preview" no-clamp>
        <media-slider-value class="vds-slider-value"></media-slider-value>
      </media-slider-preview>
      <div class="vds-slider-thumb"></div>
    </media-volume-slider>
  `}function Bt(){const e=_(void 0),t=_(0),{thumbnails:s,translations:n,sliderChaptersMinWidth:a,disableTimeSlider:l,seekStep:r,noScrubGesture:p}=d(),u=c(n,"Seek"),v=o(l),f=o(()=>t()<a()),m=o(s);return qt(e,()=>{const x=e();x&&t.set(x.clientWidth)}),i`
    <media-time-slider
      class="vds-time-slider vds-slider"
      aria-label=${u}
      key-step=${o(r)}
      ?disabled=${v}
      ?no-swipe-gesture=${o(p)}
      ${at(e.set)}
    >
      <media-slider-chapters class="vds-slider-chapters" ?disabled=${f}>
        <template>
          <div class="vds-slider-chapter">
            <div class="vds-slider-track"></div>
            <div class="vds-slider-track-fill vds-slider-track"></div>
            <div class="vds-slider-progress vds-slider-track"></div>
          </div>
        </template>
      </media-slider-chapters>
      <div class="vds-slider-thumb"></div>
      <media-slider-preview class="vds-slider-preview">
        <media-slider-thumbnail
          class="vds-slider-thumbnail vds-thumbnail"
          .src=${m}
        ></media-slider-thumbnail>
        <div class="vds-slider-chapter-title" data-part="chapter-title"></div>
        <media-slider-value class="vds-slider-value"></media-slider-value>
      </media-slider-preview>
    </media-time-slider>
  `}function Zs(){return i`
    <div class="vds-time-group">
      ${o(()=>{const{duration:e}=g();return e()?[i`<media-time class="vds-time" type="current"></media-time>`,i`<div class="vds-time-divider">/</div>`,i`<media-time class="vds-time" type="duration"></media-time>`]:null})}
    </div>
  `}function Js(){return o(()=>{const{live:e,duration:t}=g();return e()?fe():t()?i`<media-time class="vds-time" type="current" toggle remainder></media-time>`:null})}function we(){return o(()=>{const{live:e}=g();return e()?fe():Zs()})}function ke(){return o(()=>{const{textTracks:e}=y(),{title:t,started:s}=g(),n=_(null);return Zt(e,"chapters",n.set),n()&&(s()||!t())?Se():i`<media-title class="vds-chapter-title"></media-title>`})}function Se(){return i`<media-chapter-title class="vds-chapter-title"></media-chapter-title>`}class Ce extends ue{async loadIcons(){const t=(await ne(async()=>{const{icons:n}=await import("./vidstack-BTigPj2h-BYwaDJQl.js");return{icons:n}},__vite__mapDeps([0,1,2]))).icons,s={};for(const n of Object.keys(t))s[n]=re({name:n,paths:t[n]});return s}}let Xs=class extends St{static props={...super.props,when:({viewType:t})=>t==="audio",smallWhen:({width:t})=>t<576}};function tn(){return[Ct(),Pt(),i`
      <media-controls class="vds-controls">
        <media-controls-group class="vds-controls-group">
          ${[Et({backward:!0,tooltip:"top start"}),it({tooltip:"top"}),Et({tooltip:"top"}),en(),Bt(),Js(),Ot({orientation:"vertical",tooltip:"top"}),Dt({tooltip:"top"}),Mt(),At({tooltip:"top"}),sn()]}
        </media-controls-group>
      </media-controls>
    `]}function en(){return o(()=>{let e=_(void 0),t=_(!1),s=y(),{title:n,started:a,currentTime:l,ended:r}=g(),{translations:p}=d(),u=Qe(e),v=()=>a()||l()>0;const f=()=>{const T=r()?"Replay":v()?"Continue":"Play";return`${C(p,T)}: ${n()}`};S(()=>{u()&&document.activeElement===document.body&&s.player.el?.focus({preventScroll:!0})});function m(){const T=e(),w=!!T&&!u()&&T.clientWidth<T.children[0].clientWidth;T&&He(T,"vds-marquee",w),t.set(w)}function x(){return i`
        <span class="vds-title-text">
          ${o(f)}${o(()=>v()?Se():null)}
        </span>
      `}return qt(e,m),n()?i`
          <span class="vds-title" title=${o(f)} ${at(e.set)}>
            ${[x(),o(()=>t()&&!u()?x():null)]}
          </span>
        `:I()})}function sn(){const e="top end";return[ye({tooltip:"top",placement:e,portal:!0}),Te({tooltip:"top end",placement:e,portal:!0})]}class nn extends gt(xt,Xs){static tagName="media-audio-layout";static attrs={smallWhen:{converter(t){return t!=="never"&&!!t}}};#t;#e=_(!1);onSetup(){this.forwardKeepAlive=!1,this.#t=y(),this.classList.add("vds-audio-layout"),this.#a()}onConnect(){pe("audio",()=>this.isMatch),this.#n()}render(){return o(this.#s.bind(this))}#s(){return this.isMatch?tn():null}#n(){const{menuPortal:t}=d();S(()=>{if(!this.isMatch)return;const s=he(this,this.menuContainer,"vds-audio-layout",()=>this.isSmallLayout),n=s?[this,s]:[this];return(this.$props.customIcons()?new kt(n):new Ce(n)).connect(),t.set(s),()=>{s.remove(),t.set(null)}})}#a(){const{pointer:t}=this.#t.$state;S(()=>{t()==="coarse"&&S(this.#i.bind(this))})}#i(){if(!this.#e()){J(this,"pointerdown",this.#o.bind(this),{capture:!0});return}J(this,"pointerdown",t=>t.stopPropagation()),J(window,"pointerdown",this.#l.bind(this))}#o(t){const{target:s}=t;Ke(s)&&s.closest(".vds-time-slider")&&(t.stopImmediatePropagation(),this.setAttribute("data-scrubbing",""),this.#e.set(!0))}#l(){this.#e.set(!1),this.removeAttribute("data-scrubbing")}}const an=U(class extends wt{constructor(){super(...arguments),this.key=N}render(e,t){return this.key=e,t}update(e,[t,s]){return t!==this.key&&(Xe(e),this.key=t),s}});class on extends St{static props={...super.props,when:({viewType:t})=>t==="video",smallWhen:({width:t,height:s})=>t<576||s<380}}function Ae(){return o(()=>{const e=y(),{noKeyboardAnimations:t,userPrefersKeyboardAnimations:s}=d();if(b(()=>t()||!s())())return null;const a=_(!1),{lastKeyboardAction:l}=e.$state;S(()=>{a.set(!!l());const m=setTimeout(()=>a.set(!1),500);return()=>{a.set(!1),window.clearTimeout(m)}});const r=b(()=>{const m=l()?.action;return m&&a()?q(m):null}),p=b(()=>`vds-kb-action${a()?"":" hidden"}`),u=b(ln),v=b(()=>{const m=rn();return m?qe(m):null});function f(){const m=v();return m?i`
        <div class="vds-kb-bezel">
          <div class="vds-kb-icon">${m}</div>
        </div>
      `:null}return i`
      <div class=${o(p)} data-action=${o(r)}>
        <div class="vds-kb-text-wrapper">
          <div class="vds-kb-text">${o(u)}</div>
        </div>
        ${o(()=>an(l(),f()))}
      </div>
    `})}function ln(){const{$state:e}=y(),t=e.lastKeyboardAction()?.action,s=e.audioGain()??1;switch(t){case"toggleMuted":return e.muted()?"0%":Wt(e.volume(),s);case"volumeUp":case"volumeDown":return Wt(e.volume(),s);default:return""}}function Wt(e,t){return`${Math.round(e*t*100)}%`}function rn(){const{$state:e}=y();switch(e.lastKeyboardAction()?.action){case"togglePaused":return e.paused()?"kb-pause-icon":"kb-play-icon";case"toggleMuted":return e.muted()||e.volume()===0?"kb-mute-icon":e.volume()>=.5?"kb-volume-up-icon":"kb-volume-down-icon";case"toggleFullscreen":return`kb-fs-${e.fullscreen()?"enter":"exit"}-icon`;case"togglePictureInPicture":return`kb-pip-${e.pictureInPicture()?"enter":"exit"}-icon`;case"toggleCaptions":return e.hasCaptions()?`kb-cc-${e.textTrack()?"on":"off"}-icon`:null;case"volumeUp":return"kb-volume-up-icon";case"volumeDown":return"kb-volume-down-icon";case"seekForward":return"kb-seek-forward-icon";case"seekBackward":return"kb-seek-backward-icon";default:return null}}function un(){return[Ct(),De(),dt(),Ae(),Pt(),i`<div class="vds-scrim"></div>`,i`
      <media-controls class="vds-controls">
        ${[cn(),I(),i`<media-controls-group class="vds-controls-group"></media-controls-group>`,I(),i`
            <media-controls-group class="vds-controls-group">
              ${Bt()}
            </media-controls-group>
          `,i`
            <media-controls-group class="vds-controls-group">
              ${[it({tooltip:"top start"}),Ot({orientation:"horizontal",tooltip:"top"}),we(),ke(),Dt({tooltip:"top"}),dn(),At({tooltip:"top"}),me({tooltip:"top"}),Mt(),ls(),ve({tooltip:"top end"})]}
            </media-controls-group>
          `]}
      </media-controls>
    `]}function dn(){return o(()=>{const{menuGroup:e}=d();return e()==="bottom"?Gt():null})}function cn(){return i`
    <media-controls-group class="vds-controls-group">
      ${o(()=>{const{menuGroup:e}=d();return e()==="top"?[I(),Gt()]:null})}
    </media-controls-group>
  `}function pn(){return[Ct(),De(),dt(),Pt(),Ae(),i`<div class="vds-scrim"></div>`,i`
      <media-controls class="vds-controls">
        <media-controls-group class="vds-controls-group">
          ${[At({tooltip:"top start"}),me({tooltip:"bottom start"}),I(),Dt({tooltip:"bottom"}),Mt(),Gt(),Ot({orientation:"vertical",tooltip:"bottom end"})]}
        </media-controls-group>

        ${I()}

        <media-controls-group class="vds-controls-group" style="pointer-events: none;">
          ${[I(),it({tooltip:"top"}),I()]}
        </media-controls-group>

        ${I()}

        <media-controls-group class="vds-controls-group">
          ${[we(),ke(),ve({tooltip:"top end"})]}
        </media-controls-group>

        <media-controls-group class="vds-controls-group">
          ${Bt()}
        </media-controls-group>
      </media-controls>
    `,vn()]}function mn(){return i`
    <div class="vds-load-container">
      ${[dt(),it({tooltip:"top"})]}
    </div>
  `}function vn(){return o(()=>{const{duration:e}=g();return e()===0?null:i`
      <div class="vds-start-duration">
        <media-time class="vds-time" type="duration"></media-time>
      </div>
    `})}function dt(){return i`
    <div class="vds-buffering-indicator">
      <media-spinner class="vds-buffering-spinner"></media-spinner>
    </div>
  `}function Gt(){const{menuGroup:e,smallWhen:t}=d(),s=()=>e()==="top"||t()?"bottom":"top",n=b(()=>`${s()} ${e()==="top"?"end":"center"}`),a=b(()=>`${s()} end`);return[ye({tooltip:n,placement:a,portal:!0}),Te({tooltip:n,placement:a,portal:!0})]}function De(){return o(()=>{const{noGestures:e}=d();return e()?null:i`
      <div class="vds-gestures">
        <media-gesture class="vds-gesture" event="pointerup" action="toggle:paused"></media-gesture>
        <media-gesture
          class="vds-gesture"
          event="pointerup"
          action="toggle:controls"
        ></media-gesture>
        <media-gesture
          class="vds-gesture"
          event="dblpointerup"
          action="toggle:fullscreen"
        ></media-gesture>
        <media-gesture class="vds-gesture" event="dblpointerup" action="seek:-10"></media-gesture>
        <media-gesture class="vds-gesture" event="dblpointerup" action="seek:10"></media-gesture>
      </div>
    `})}class fn extends gt(xt,on){static tagName="media-video-layout";static attrs={smallWhen:{converter(t){return t!=="never"&&!!t}}};#t;onSetup(){this.forwardKeepAlive=!1,this.#t=y(),this.classList.add("vds-video-layout")}onConnect(){pe("video",()=>this.isMatch),this.#e()}render(){return o(this.#s.bind(this))}#e(){const{menuPortal:t}=d();S(()=>{if(!this.isMatch)return;const s=he(this,this.menuContainer,"vds-video-layout",()=>this.isSmallLayout),n=s?[this,s]:[this];return(this.$props.customIcons()?new kt(n):new Ce(n)).connect(),t.set(s),()=>{s.remove(),t.set(null)}})}#s(){const{load:t}=this.#t.$props,{canLoad:s,streamType:n,nativeControls:a}=this.#t.$state;return!a()&&this.isMatch?t()==="play"&&!s()?mn():n()==="unknown"?dt():this.isSmallLayout?pn():un():null}}const Me=Jt();function $(){return Ht(Me)}const $n={clickToPlay:!0,clickToFullscreen:!0,controls:["play-large","play","progress","current-time","mute+volume","captions","settings","pip","airplay","fullscreen"],customIcons:!1,displayDuration:!1,download:null,markers:null,invertTime:!0,thumbnails:null,toggleTime:!0,translations:null,seekTime:10,speed:[.5,.75,1,1.25,1.5,1.75,2,4]};class hn extends Xt{static props=$n;#t;onSetup(){this.#t=y(),te(Me,{...this.$props,previewTime:_(0)})}}function yn(e,t){const{canAirPlay:s,canFullscreen:n,canPictureInPicture:a,controlsHidden:l,currentTime:r,fullscreen:p,hasCaptions:u,isAirPlayConnected:v,paused:f,pictureInPicture:m,playing:x,pointer:T,poster:w,textTrack:M,viewType:k,waiting:O}=t.$state;e.classList.add("plyr"),e.classList.add("plyr--full-ui");const Y={"plyr--airplay-active":v,"plyr--airplay-supported":s,"plyr--fullscreen-active":p,"plyr--fullscreen-enabled":n,"plyr--hide-controls":l,"plyr--is-touch":()=>T()==="coarse","plyr--loading":O,"plyr--paused":f,"plyr--pip-active":m,"plyr--pip-enabled":a,"plyr--playing":x,"plyr__poster-enabled":w,"plyr--stopped":()=>f()&&r()===0,"plyr--captions-active":M,"plyr--captions-enabled":u},R=Ue();for(const P of Object.keys(Y))R.add(S(()=>{e.classList.toggle(P,!!Y[P]())}));return R.add(S(()=>{const P=`plyr--${k()}`;return e.classList.add(P),()=>e.classList.remove(P)}),S(()=>{const{$provider:P}=t,W=P()?.type,K=`plyr--${bn(W)?"html5":W}`;return e.classList.toggle(K,!!W),()=>e.classList.remove(K)})),()=>R.empty()}function bn(e){return e==="audio"||e==="video"}class gn extends ue{async loadIcons(){const t=(await ne(async()=>{const{icons:n}=await import("./vidstack-DXxIKXmd-Dge3KT8k.js");return{icons:n}},[])).icons,s={};for(const n of Object.keys(t))s[n]=re({name:n,paths:t[n],viewBox:"0 0 18 18"});return s}}function ct(e,t){return e()?.[t]??t}function _n(){return kn()}function xn(){const e=y(),{load:t}=e.$props,{canLoad:s}=e.$state;return b(()=>t()==="play"&&!s())()?[Pe(),Kt()]:[Tn(),wn(),Kt(),Sn(),Rn(),Wn()]}function Pe(){const e=y(),{translations:t}=$(),{title:s}=e.$state,n=o(()=>`${ct(t,"Play")}, ${s()}`);return i`
    <media-play-button
      class="plyr__control plyr__control--overlaid"
      aria-label=${n}
      data-plyr="play"
    >
      <slot name="play-icon"></slot>
    </button>
  `}function Tn(){const{controls:e}=$();return o(()=>e().includes("play-large")?Pe():null)}function wn(){const{thumbnails:e,previewTime:t}=$();return i`
    <media-thumbnail
      .src=${o(e)}
      class="plyr__preview-scrubbing"
      time=${o(()=>t())}
    ></media-thumbnail>
  `}function Kt(){const e=y(),{poster:t}=e.$state,s=o(()=>`background-image: url("${t()}");`);return i`<div class="plyr__poster" style=${s}></div>`}function kn(){const e=new Set(["captions","pip","airplay","fullscreen"]),{controls:t}=$(),s=o(()=>t().filter(n=>!e.has(n)).map(Ie));return i`<div class="plyr__controls">${s}</div>`}function Sn(){const{controls:e}=$(),t=o(()=>e().map(Ie));return i`<div class="plyr__controls">${t}</div>`}function Ie(e){switch(e){case"airplay":return Cn();case"captions":return An();case"current-time":return Fn();case"download":return En();case"duration":return Le();case"fast-forward":return Bn();case"fullscreen":return Dn();case"mute":case"volume":case"mute+volume":return Nn(e);case"pip":return Pn();case"play":return In();case"progress":return Gn();case"restart":return Ln();case"rewind":return On();case"settings":return Kn();default:return null}}function Cn(){const{translations:e}=$();return i`
    <media-airplay-button class="plyr__controls__item plyr__control" data-plyr="airplay">
      <slot name="airplay-icon"></slot>
      <span class="plyr__tooltip">${h(e,"AirPlay")}</span>
    </media-airplay-button>
  `}function An(){const{translations:e}=$(),t=h(e,"Disable captions"),s=h(e,"Enable captions");return i`
    <media-caption-button
      class="plyr__controls__item plyr__control"
      data-no-label
      data-plyr="captions"
    >
      <slot name="captions-on-icon" data-class="icon--pressed"></slot>
      <slot name="captions-off-icon" data-class="icon--not-pressed"></slot>
      <span class="label--pressed plyr__tooltip">${t}</span>
      <span class="label--not-pressed plyr__tooltip">${s}</span>
    </media-caption-button>
  `}function Dn(){const{translations:e}=$(),t=h(e,"Enter Fullscreen"),s=h(e,"Exit Fullscreen");return i`
    <media-fullscreen-button
      class="plyr__controls__item plyr__control"
      data-no-label
      data-plyr="fullscreen"
    >
      <slot name="enter-fullscreen-icon" data-class="icon--pressed"></slot>
      <slot name="exit-fullscreen-icon" data-class="icon--not-pressed"></slot>
      <span class="label--pressed plyr__tooltip">${s}</span>
      <span class="label--not-pressed plyr__tooltip">${t}</span>
    </media-fullscreen-button>
  `}function Mn(){const{translations:e}=$(),t=h(e,"Mute"),s=h(e,"Unmute");return i`
    <media-mute-button class="plyr__control" data-no-label data-plyr="mute">
      <slot name="muted-icon" data-class="icon--pressed"></slot>
      <slot name="volume-icon" data-class="icon--not-pressed"></slot>
      <span class="label--pressed plyr__tooltip">${s}</span>
      <span class="label--not-pressed plyr__tooltip">${t}</span>
    </media-mute-button>
  `}function Pn(){const{translations:e}=$(),t=h(e,"Enter PiP"),s=h(e,"Exit PiP");return i`
    <media-pip-button class="plyr__controls__item plyr__control" data-no-label data-plyr="pip">
      <slot name="pip-icon"></slot>
      <slot name="enter-pip-icon" data-class="icon--pressed"></slot>
      <slot name="exit-pip-icon" data-class="icon--not-pressed"></slot>
      <span class="label--pressed plyr__tooltip">${s}</span>
      <span class="label--not-pressed plyr__tooltip">${t}</span>
    </media-pip-button>
  `}function In(){const{translations:e}=$(),t=h(e,"Play"),s=h(e,"Pause");return i`
    <media-play-button class="plyr__controls__item plyr__control" data-no-label data-plyr="play">
      <slot name="pause-icon" data-class="icon--pressed"></slot>
      <slot name="play-icon" data-class="icon--not-pressed"></slot>
      <span class="label--pressed plyr__tooltip">${s}</span>
      <span class="label--not-pressed plyr__tooltip">${t}</span>
    </media-play-button>
  `}function Ln(){const{translations:e}=$(),{remote:t}=y(),s=h(e,"Restart");function n(a){se(a)&&!bt(a)||t.seek(0,a)}return i`
    <button
      type="button"
      class="plyr__control"
      data-plyr="restart"
      @pointerup=${n}
      @keydown=${n}
    >
      <slot name="restart-icon"></slot>
      <span class="plyr__tooltip">${s}</span>
    </button>
  `}function On(){const{translations:e,seekTime:t}=$(),s=o(()=>`${ct(e,"Rewind")} ${t()}s`),n=o(()=>-1*t());return i`
    <media-seek-button
      class="plyr__controls__item plyr__control"
      seconds=${n}
      data-no-label
      data-plyr="rewind"
    >
      <slot name="rewind-icon"></slot>
      <span class="plyr__tooltip">${s}</span>
    </media-seek-button>
  `}function Bn(){const{translations:e,seekTime:t}=$(),s=o(()=>`${ct(e,"Forward")} ${t()}s`),n=o(t);return i`
    <media-seek-button
      class="plyr__controls__item plyr__control"
      seconds=${n}
      data-no-label
      data-plyr="fast-forward"
    >
      <slot name="fast-forward-icon"></slot>
      <span class="plyr__tooltip">${s}</span>
    </media-seek-button>
  `}function Gn(){let e=y(),{duration:t,viewType:s}=e.$state,{translations:n,markers:a,thumbnails:l,seekTime:r,previewTime:p}=$(),u=h(n,"Seek"),v=_(null),f=o(()=>{const k=v();return k?i`<span class="plyr__progress__marker-label">${ae(k.label)}<br /></span>`:null});function m(k){p.set(k.detail)}function x(){v.set(this)}function T(){v.set(null)}function w(){const k=l(),O=o(()=>s()==="audio");return k?i`
          <media-slider-preview class="plyr__slider__preview" ?no-clamp=${O}>
            <media-slider-thumbnail .src=${k} class="plyr__slider__preview__thumbnail">
              <span class="plyr__slider__preview__time-container">
                ${f}
                <media-slider-value class="plyr__slider__preview__time"></media-slider-value>
              </span>
            </media-slider-thumbnail>
          </media-slider-preview>
        `:i`
          <span class="plyr__tooltip">
            ${f}
            <media-slider-value></media-slider-value>
          </span>
        `}function M(){const k=t();return Number.isFinite(k)?a()?.map(O=>i`
        <span
          class="plyr__progress__marker"
          @mouseenter=${x.bind(O)}
          @mouseleave=${T}
          style=${`left: ${O.time/k*100}%;`}
        ></span>
      `):null}return i`
    <div class="plyr__controls__item plyr__progress__container">
      <div class="plyr__progress">
        <media-time-slider
          class="plyr__slider"
          data-plyr="seek"
          pause-while-dragging
          key-step=${o(r)}
          aria-label=${u}
          @media-seeking-request=${m}
        >
          <div class="plyr__slider__track"></div>
          <div class="plyr__slider__thumb"></div>
          <div class="plyr__slider__buffer"></div>
          ${o(w)}${o(M)}
        </media-time-slider>
      </div>
    </div>
  `}function Nn(e){return o(()=>{const t=e==="mute"||e==="mute+volume",s=e==="volume"||e==="mute+volume";return i`
      <div class="plyr__controls__item plyr__volume">
        ${[t?Mn():null,s?Vn():null]}
      </div>
    `})}function Vn(){const{translations:e}=$(),t=h(e,"Volume");return i`
    <media-volume-slider class="plyr__slider" data-plyr="volume" aria-label=${t}>
      <div class="plyr__slider__track"></div>
      <div class="plyr__slider__thumb"></div>
    </media-volume-slider>
  `}function Fn(){const e=y(),{translations:t,invertTime:s,toggleTime:n,displayDuration:a}=$(),l=_(ht(s));function r(u){!n()||a()||se(u)&&!bt(u)||l.set(v=>!v)}function p(){return o(()=>a()?Le():null)}return o(()=>{const{streamType:u}=e.$state,v=h(t,"LIVE"),f=h(t,"Current time"),m=o(()=>!a()&&l());return u()==="live"||u()==="ll-live"?i`
          <media-live-button
            class="plyr__controls__item plyr__control plyr__live-button"
            data-plyr="live"
          >
            <span class="plyr__live-button__text">${v}</span>
          </media-live-button>
        `:i`
          <media-time
            type="current"
            class="plyr__controls__item plyr__time plyr__time--current"
            tabindex="0"
            role="timer"
            aria-label=${f}
            ?remainder=${m}
            @pointerup=${r}
            @keydown=${r}
          ></media-time>
          ${p()}
        `})}function Le(){const{translations:e}=$(),t=h(e,"Duration");return i`
    <media-time
      type="duration"
      class="plyr__controls__item plyr__time plyr__time--duration"
      role="timer"
      tabindex="0"
      aria-label=${t}
    ></media-time>
  `}function En(){return o(()=>{const e=y(),{translations:t,download:s}=$(),{title:n,source:a}=e.$state,l=a(),r=s(),p=jt({title:n(),src:l,download:r}),u=h(t,"Download");return B(p?.url)?i`
          <a
            class="plyr__controls__item plyr__control"
            href=${Yt(p.url,{download:p.name})}
            download=${p.name}
            target="_blank"
          >
            <slot name="download-icon" />
            <span class="plyr__tooltip">${u}</span>
          </a>
        `:null})}function Rn(){return o(()=>{const{clickToPlay:e,clickToFullscreen:t}=$();return[e()?i`
            <media-gesture
              class="plyr__gesture"
              event="pointerup"
              action="toggle:paused"
            ></media-gesture>
          `:null,t()?i`
            <media-gesture
              class="plyr__gesture"
              event="dblpointerup"
              action="toggle:fullscreen"
            ></media-gesture>
          `:null]})}function Wn(){const e=y(),t=_(void 0),s=o(()=>ae(t()?.text));return S(()=>{const n=e.$state.textTrack();if(!n)return;function a(){t.set(n?.activeCues[0])}return a(),J(n,"cue-change",a)}),i`
    <div class="plyr__captions" dir="auto">
      <span class="plyr__caption">${s}</span>
    </div>
  `}function Kn(){const{translations:e}=$(),t=h(e,"Settings");return i`
    <div class="plyr__controls__item plyr__menu">
      <media-menu>
        <media-menu-button class="plyr__control" data-plyr="settings">
          <slot name="settings-icon" />
          <span class="plyr__tooltip">${t}</span>
        </media-menu-button>
        <media-menu-items class="plyr__menu__container" placement="top end">
          <div><div>${[Hn(),jn(),Zn(),Un()]}</div></div>
        </media-menu-items>
      </media-menu>
    </div>
  `}function pt({label:e,children:t}){const s=_(!1);return i`
    <media-menu @open=${()=>s.set(!0)} @close=${()=>s.set(!1)}>
      ${Qn({label:e,open:s})}
      <media-menu-items>${t}</media-menu-items>
    </media-menu>
  `}function Qn({open:e,label:t}){const{translations:s}=$(),n=o(()=>`plyr__control plyr__control--${e()?"back":"forward"}`);function a(){const l=h(s,"Go back to previous menu");return o(()=>e()?i`<span class="plyr__sr-only">${l}</span>`:null)}return i`
    <media-menu-button class=${n} data-plyr="settings">
      <span class="plyr__menu__label" aria-hidden=${Xn(e)}>
        ${h(s,t)}
      </span>
      <span class="plyr__menu__value" data-part="hint"></span>
      ${a()}
    </media-menu-button>
  `}function Hn(){return pt({label:"Audio",children:qn()})}function qn(){const{translations:e}=$();return i`
    <media-audio-radio-group empty-label=${h(e,"Default")}>
      <template>
        <media-radio class="plyr__control" data-plyr="audio">
          <span data-part="label"></span>
        </media-radio>
      </template>
    </media-audio-radio-group>
  `}function Un(){return pt({label:"Speed",children:zn()})}function zn(){const{translations:e,speed:t}=$();return i`
    <media-speed-radio-group .rates=${t} normal-label=${h(e,"Normal")}>
      <template>
        <media-radio class="plyr__control" data-plyr="speed">
          <span data-part="label"></span>
        </media-radio>
      </template>
    </media-speed-radio-group>
  `}function jn(){return pt({label:"Captions",children:Yn()})}function Yn(){const{translations:e}=$();return i`
    <media-captions-radio-group off-label=${h(e,"Disabled")}>
      <template>
        <media-radio class="plyr__control" data-plyr="captions">
          <span data-part="label"></span>
        </media-radio>
      </template>
    </media-captions-radio-group>
  `}function Zn(){return pt({label:"Quality",children:Jn()})}function Jn(){const{translations:e}=$();return i`
    <media-quality-radio-group auto-label=${h(e,"Auto")}>
      <template>
        <media-radio class="plyr__control" data-plyr="quality">
          <span data-part="label"></span>
        </media-radio>
      </template>
    </media-quality-radio-group>
  `}function Xn(e){return o(()=>e()?"true":"false")}function h(e,t){return o(()=>ct(e,t))}class ta extends gt(xt,hn){static tagName="media-plyr-layout";#t;onSetup(){this.forwardKeepAlive=!1,this.#t=y()}onConnect(){this.#t.player.el?.setAttribute("data-layout","plyr"),E(()=>this.#t.player.el?.removeAttribute("data-layout")),yn(this,this.#t),S(()=>{this.$props.customIcons()?new kt([this]).connect():new gn([this]).connect()})}render(){return o(this.#e.bind(this))}#e(){const{viewType:t}=this.#t.$state;return t()==="audio"?_n():t()==="video"?xn():null}}_t(nn);_t(fn);_t(ta);
