import{n as s}from"./pluginUiLoader-DyDx6SnK.js";const h=s("user-round",[["circle",{cx:"12",cy:"8",r:"5",key:"1hypcn"}],["path",{d:"M20 21a8 8 0 0 0-16 0",key:"rfgkzh"}]]),d="checkout-builder-preview-config",l="checkout-builder-preview-ack",p="__checkoutBuilderApplyPreview",o="checkout-builder-preview";function w(){return typeof window>"u"?!1:new URLSearchParams(window.location.search).get("preview")==="1"}function m(t){if(!(typeof BroadcastChannel>"u"))try{const e=new BroadcastChannel(o);e.postMessage(t),e.close()}catch{}}function y(t){if(typeof BroadcastChannel>"u")return()=>{};const e=new BroadcastChannel(o),n=r=>{r?.data?.type===d&&t(r.data)};return e.addEventListener("message",n),()=>{e.removeEventListener("message",n),e.close()}}const i="getfy-checkout-embed-resize";function a(){if(typeof window>"u")return!1;try{return window.self!==window.top}catch{return!0}}function u(t){!a()||typeof window.parent?.postMessage!="function"||window.parent.postMessage({type:i,height:Math.max(320,Math.ceil(Number(t)||0))},"*")}function E(t){if(typeof window>"u"||!t||!a())return()=>{};const e=()=>{const c=Math.max(document.documentElement.scrollHeight,document.body?.scrollHeight??0,t.scrollHeight??0);u(c)},n=typeof ResizeObserver<"u"?new ResizeObserver(e):null;n?.observe(t),n?.observe(document.documentElement);const r=typeof MutationObserver<"u"?new MutationObserver(e):null;return r?.observe(document.body,{childList:!0,subtree:!0,attributes:!0}),window.addEventListener("load",e),e(),()=>{n?.disconnect(),r?.disconnect(),window.removeEventListener("load",e)}}function g(t,e=720){return`<!-- Getfy Checkout -->
<iframe
  data-getfy-checkout
  src="${String(t||"").replace(/"/g,"&quot;")}"
  title="Checkout"
  style="width:100%;min-height:${e}px;border:0;display:block;"
  allow="payment *"
  loading="lazy"
></iframe>
<script>
window.addEventListener('message', function (event) {
  if (!event.data || event.data.type !== '${i}') return;
  var height = event.data.height;
  if (!height) return;
  document.querySelectorAll('iframe[data-getfy-checkout]').forEach(function (frame) {
    frame.style.height = height + 'px';
  });
});
<\/script>`}export{l as P,h as U,p as a,d as b,m as c,g as d,a as e,y as f,w as i,E as s};
