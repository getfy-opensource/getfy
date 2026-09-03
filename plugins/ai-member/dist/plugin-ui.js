import { h as Ve, ref as S, watch as Et, openBlock as x, createBlock as H, Teleport as Ct, createElementBlock as y, createElementVNode as i, createVNode as T, unref as w, createTextVNode as B, Fragment as he, withDirectives as D, vModelText as Y, vModelCheckbox as ye, toDisplayString as N, createCommentVNode as j, computed as Z, onMounted as St, normalizeClass as P, normalizeStyle as de, vModelSelect as at, renderList as Je, nextTick as sn, Transition as rn, withCtx as on, withKeys as an, withModifiers as ln } from "vue";
function At(e, t) {
  return function() {
    return e.apply(t, arguments);
  };
}
const { toString: cn } = Object.prototype, { getPrototypeOf: tt } = Object, { iterator: Ue, toStringTag: Ot } = Symbol, Fe = /* @__PURE__ */ ((e) => (t) => {
  const n = cn.call(t);
  return e[n] || (e[n] = n.slice(8, -1).toLowerCase());
})(/* @__PURE__ */ Object.create(null)), oe = (e) => (e = e.toLowerCase(), (t) => Fe(t) === e), Le = (e) => (t) => typeof t === e, { isArray: be } = Array, ge = Le("undefined");
function we(e) {
  return e !== null && !ge(e) && e.constructor !== null && !ge(e.constructor) && G(e.constructor.isBuffer) && e.constructor.isBuffer(e);
}
const Tt = oe("ArrayBuffer");
function dn(e) {
  let t;
  return typeof ArrayBuffer < "u" && ArrayBuffer.isView ? t = ArrayBuffer.isView(e) : t = e && e.buffer && Tt(e.buffer), t;
}
const un = Le("string"), G = Le("function"), $t = Le("number"), ke = (e) => e !== null && typeof e == "object", fn = (e) => e === !0 || e === !1, Ae = (e) => {
  if (Fe(e) !== "object")
    return !1;
  const t = tt(e);
  return (t === null || t === Object.prototype || Object.getPrototypeOf(t) === null) && !(Ot in e) && !(Ue in e);
}, pn = (e) => {
  if (!ke(e) || we(e))
    return !1;
  try {
    return Object.keys(e).length === 0 && Object.getPrototypeOf(e) === Object.prototype;
  } catch {
    return !1;
  }
}, mn = oe("Date"), hn = oe("File"), gn = (e) => !!(e && typeof e.uri < "u"), bn = (e) => e && typeof e.getParts < "u", xn = oe("Blob"), vn = oe("FileList"), yn = (e) => ke(e) && G(e.pipe);
function wn() {
  return typeof globalThis < "u" ? globalThis : typeof self < "u" ? self : typeof window < "u" ? window : typeof global < "u" ? global : {};
}
const it = wn(), lt = typeof it.FormData < "u" ? it.FormData : void 0, kn = (e) => {
  let t;
  return e && (lt && e instanceof lt || G(e.append) && ((t = Fe(e)) === "formdata" || // detect form-data instance
  t === "object" && G(e.toString) && e.toString() === "[object FormData]"));
}, _n = oe("URLSearchParams"), [zn, Rn, En, Cn] = [
  "ReadableStream",
  "Request",
  "Response",
  "Headers"
].map(oe), Sn = (e) => e.trim ? e.trim() : e.replace(/^[\s\uFEFF\xA0]+|[\s\uFEFF\xA0]+$/g, "");
function _e(e, t, { allOwnKeys: n = !1 } = {}) {
  if (e === null || typeof e > "u")
    return;
  let s, r;
  if (typeof e != "object" && (e = [e]), be(e))
    for (s = 0, r = e.length; s < r; s++)
      t.call(null, e[s], s, e);
  else {
    if (we(e))
      return;
    const o = n ? Object.getOwnPropertyNames(e) : Object.keys(e), a = o.length;
    let c;
    for (s = 0; s < a; s++)
      c = o[s], t.call(null, e[c], c, e);
  }
}
function Nt(e, t) {
  if (we(e))
    return null;
  t = t.toLowerCase();
  const n = Object.keys(e);
  let s = n.length, r;
  for (; s-- > 0; )
    if (r = n[s], t === r.toLowerCase())
      return r;
  return null;
}
const ue = typeof globalThis < "u" ? globalThis : typeof self < "u" ? self : typeof window < "u" ? window : global, Pt = (e) => !ge(e) && e !== ue;
function Ke() {
  const { caseless: e, skipUndefined: t } = Pt(this) && this || {}, n = {}, s = (r, o) => {
    if (o === "__proto__" || o === "constructor" || o === "prototype")
      return;
    const a = e && Nt(n, o) || o;
    Ae(n[a]) && Ae(r) ? n[a] = Ke(n[a], r) : Ae(r) ? n[a] = Ke({}, r) : be(r) ? n[a] = r.slice() : (!t || !ge(r)) && (n[a] = r);
  };
  for (let r = 0, o = arguments.length; r < o; r++)
    arguments[r] && _e(arguments[r], s);
  return n;
}
const An = (e, t, n, { allOwnKeys: s } = {}) => (_e(
  t,
  (r, o) => {
    n && G(r) ? Object.defineProperty(e, o, {
      value: At(r, n),
      writable: !0,
      enumerable: !0,
      configurable: !0
    }) : Object.defineProperty(e, o, {
      value: r,
      writable: !0,
      enumerable: !0,
      configurable: !0
    });
  },
  { allOwnKeys: s }
), e), On = (e) => (e.charCodeAt(0) === 65279 && (e = e.slice(1)), e), Tn = (e, t, n, s) => {
  e.prototype = Object.create(t.prototype, s), Object.defineProperty(e.prototype, "constructor", {
    value: e,
    writable: !0,
    enumerable: !1,
    configurable: !0
  }), Object.defineProperty(e, "super", {
    value: t.prototype
  }), n && Object.assign(e.prototype, n);
}, $n = (e, t, n, s) => {
  let r, o, a;
  const c = {};
  if (t = t || {}, e == null) return t;
  do {
    for (r = Object.getOwnPropertyNames(e), o = r.length; o-- > 0; )
      a = r[o], (!s || s(a, e, t)) && !c[a] && (t[a] = e[a], c[a] = !0);
    e = n !== !1 && tt(e);
  } while (e && (!n || n(e, t)) && e !== Object.prototype);
  return t;
}, Nn = (e, t, n) => {
  e = String(e), (n === void 0 || n > e.length) && (n = e.length), n -= t.length;
  const s = e.indexOf(t, n);
  return s !== -1 && s === n;
}, Pn = (e) => {
  if (!e) return null;
  if (be(e)) return e;
  let t = e.length;
  if (!$t(t)) return null;
  const n = new Array(t);
  for (; t-- > 0; )
    n[t] = e[t];
  return n;
}, Un = /* @__PURE__ */ ((e) => (t) => e && t instanceof e)(typeof Uint8Array < "u" && tt(Uint8Array)), Fn = (e, t) => {
  const s = (e && e[Ue]).call(e);
  let r;
  for (; (r = s.next()) && !r.done; ) {
    const o = r.value;
    t.call(e, o[0], o[1]);
  }
}, Ln = (e, t) => {
  let n;
  const s = [];
  for (; (n = e.exec(t)) !== null; )
    s.push(n);
  return s;
}, jn = oe("HTMLFormElement"), Mn = (e) => e.toLowerCase().replace(/[-_\s]([a-z\d])(\w*)/g, function(n, s, r) {
  return s.toUpperCase() + r;
}), ct = (({ hasOwnProperty: e }) => (t, n) => e.call(t, n))(Object.prototype), In = oe("RegExp"), Ut = (e, t) => {
  const n = Object.getOwnPropertyDescriptors(e), s = {};
  _e(n, (r, o) => {
    let a;
    (a = t(r, o, e)) !== !1 && (s[o] = a || r);
  }), Object.defineProperties(e, s);
}, Dn = (e) => {
  Ut(e, (t, n) => {
    if (G(e) && ["arguments", "caller", "callee"].indexOf(n) !== -1)
      return !1;
    const s = e[n];
    if (G(s)) {
      if (t.enumerable = !1, "writable" in t) {
        t.writable = !1;
        return;
      }
      t.set || (t.set = () => {
        throw Error("Can not rewrite read-only method '" + n + "'");
      });
    }
  });
}, Bn = (e, t) => {
  const n = {}, s = (r) => {
    r.forEach((o) => {
      n[o] = !0;
    });
  };
  return be(e) ? s(e) : s(String(e).split(t)), n;
}, qn = () => {
}, Hn = (e, t) => e != null && Number.isFinite(e = +e) ? e : t;
function Vn(e) {
  return !!(e && G(e.append) && e[Ot] === "FormData" && e[Ue]);
}
const Jn = (e) => {
  const t = new Array(10), n = (s, r) => {
    if (ke(s)) {
      if (t.indexOf(s) >= 0)
        return;
      if (we(s))
        return s;
      if (!("toJSON" in s)) {
        t[r] = s;
        const o = be(s) ? [] : {};
        return _e(s, (a, c) => {
          const m = n(a, r + 1);
          !ge(m) && (o[c] = m);
        }), t[r] = void 0, o;
      }
    }
    return s;
  };
  return n(e, 0);
}, Kn = oe("AsyncFunction"), Wn = (e) => e && (ke(e) || G(e)) && G(e.then) && G(e.catch), Ft = ((e, t) => e ? setImmediate : t ? ((n, s) => (ue.addEventListener(
  "message",
  ({ source: r, data: o }) => {
    r === ue && o === n && s.length && s.shift()();
  },
  !1
), (r) => {
  s.push(r), ue.postMessage(n, "*");
}))(`axios@${Math.random()}`, []) : (n) => setTimeout(n))(typeof setImmediate == "function", G(ue.postMessage)), Gn = typeof queueMicrotask < "u" ? queueMicrotask.bind(ue) : typeof process < "u" && process.nextTick || Ft, Xn = (e) => e != null && G(e[Ue]), d = {
  isArray: be,
  isArrayBuffer: Tt,
  isBuffer: we,
  isFormData: kn,
  isArrayBufferView: dn,
  isString: un,
  isNumber: $t,
  isBoolean: fn,
  isObject: ke,
  isPlainObject: Ae,
  isEmptyObject: pn,
  isReadableStream: zn,
  isRequest: Rn,
  isResponse: En,
  isHeaders: Cn,
  isUndefined: ge,
  isDate: mn,
  isFile: hn,
  isReactNativeBlob: gn,
  isReactNative: bn,
  isBlob: xn,
  isRegExp: In,
  isFunction: G,
  isStream: yn,
  isURLSearchParams: _n,
  isTypedArray: Un,
  isFileList: vn,
  forEach: _e,
  merge: Ke,
  extend: An,
  trim: Sn,
  stripBOM: On,
  inherits: Tn,
  toFlatObject: $n,
  kindOf: Fe,
  kindOfTest: oe,
  endsWith: Nn,
  toArray: Pn,
  forEachEntry: Fn,
  matchAll: Ln,
  isHTMLForm: jn,
  hasOwnProperty: ct,
  hasOwnProp: ct,
  // an alias to avoid ESLint no-prototype-builtins detection
  reduceDescriptors: Ut,
  freezeMethods: Dn,
  toObjectSet: Bn,
  toCamelCase: Mn,
  noop: qn,
  toFiniteNumber: Hn,
  findKey: Nt,
  global: ue,
  isContextDefined: Pt,
  isSpecCompliantForm: Vn,
  toJSONObject: Jn,
  isAsyncFn: Kn,
  isThenable: Wn,
  setImmediate: Ft,
  asap: Gn,
  isIterable: Xn
};
let z = class Lt extends Error {
  static from(t, n, s, r, o, a) {
    const c = new Lt(t.message, n || t.code, s, r, o);
    return c.cause = t, c.name = t.name, t.status != null && c.status == null && (c.status = t.status), a && Object.assign(c, a), c;
  }
  /**
   * Create an Error with the specified message, config, error code, request and response.
   *
   * @param {string} message The error message.
   * @param {string} [code] The error code (for example, 'ECONNABORTED').
   * @param {Object} [config] The config.
   * @param {Object} [request] The request.
   * @param {Object} [response] The response.
   *
   * @returns {Error} The created error.
   */
  constructor(t, n, s, r, o) {
    super(t), Object.defineProperty(this, "message", {
      value: t,
      enumerable: !0,
      writable: !0,
      configurable: !0
    }), this.name = "AxiosError", this.isAxiosError = !0, n && (this.code = n), s && (this.config = s), r && (this.request = r), o && (this.response = o, this.status = o.status);
  }
  toJSON() {
    return {
      // Standard
      message: this.message,
      name: this.name,
      // Microsoft
      description: this.description,
      number: this.number,
      // Mozilla
      fileName: this.fileName,
      lineNumber: this.lineNumber,
      columnNumber: this.columnNumber,
      stack: this.stack,
      // Axios
      config: d.toJSONObject(this.config),
      code: this.code,
      status: this.status
    };
  }
};
z.ERR_BAD_OPTION_VALUE = "ERR_BAD_OPTION_VALUE";
z.ERR_BAD_OPTION = "ERR_BAD_OPTION";
z.ECONNABORTED = "ECONNABORTED";
z.ETIMEDOUT = "ETIMEDOUT";
z.ERR_NETWORK = "ERR_NETWORK";
z.ERR_FR_TOO_MANY_REDIRECTS = "ERR_FR_TOO_MANY_REDIRECTS";
z.ERR_DEPRECATED = "ERR_DEPRECATED";
z.ERR_BAD_RESPONSE = "ERR_BAD_RESPONSE";
z.ERR_BAD_REQUEST = "ERR_BAD_REQUEST";
z.ERR_CANCELED = "ERR_CANCELED";
z.ERR_NOT_SUPPORT = "ERR_NOT_SUPPORT";
z.ERR_INVALID_URL = "ERR_INVALID_URL";
const Qn = null;
function We(e) {
  return d.isPlainObject(e) || d.isArray(e);
}
function jt(e) {
  return d.endsWith(e, "[]") ? e.slice(0, -2) : e;
}
function De(e, t, n) {
  return e ? e.concat(t).map(function(r, o) {
    return r = jt(r), !n && o ? "[" + r + "]" : r;
  }).join(n ? "." : "") : t;
}
function Yn(e) {
  return d.isArray(e) && !e.some(We);
}
const Zn = d.toFlatObject(d, {}, null, function(t) {
  return /^is[A-Z]/.test(t);
});
function je(e, t, n) {
  if (!d.isObject(e))
    throw new TypeError("target must be an object");
  t = t || new FormData(), n = d.toFlatObject(
    n,
    {
      metaTokens: !0,
      dots: !1,
      indexes: !1
    },
    !1,
    function(h, b) {
      return !d.isUndefined(b[h]);
    }
  );
  const s = n.metaTokens, r = n.visitor || u, o = n.dots, a = n.indexes, m = (n.Blob || typeof Blob < "u" && Blob) && d.isSpecCompliantForm(t);
  if (!d.isFunction(r))
    throw new TypeError("visitor must be a function");
  function l(f) {
    if (f === null) return "";
    if (d.isDate(f))
      return f.toISOString();
    if (d.isBoolean(f))
      return f.toString();
    if (!m && d.isBlob(f))
      throw new z("Blob is not supported. Use a Buffer instead.");
    return d.isArrayBuffer(f) || d.isTypedArray(f) ? m && typeof Blob == "function" ? new Blob([f]) : Buffer.from(f) : f;
  }
  function u(f, h, b) {
    let C = f;
    if (d.isReactNative(t) && d.isReactNativeBlob(f))
      return t.append(De(b, h, o), l(f)), !1;
    if (f && !b && typeof f == "object") {
      if (d.endsWith(h, "{}"))
        h = s ? h : h.slice(0, -2), f = JSON.stringify(f);
      else if (d.isArray(f) && Yn(f) || (d.isFileList(f) || d.endsWith(h, "[]")) && (C = d.toArray(f)))
        return h = jt(h), C.forEach(function(U, q) {
          !(d.isUndefined(U) || U === null) && t.append(
            // eslint-disable-next-line no-nested-ternary
            a === !0 ? De([h], q, o) : a === null ? h : h + "[]",
            l(U)
          );
        }), !1;
    }
    return We(f) ? !0 : (t.append(De(b, h, o), l(f)), !1);
  }
  const g = [], v = Object.assign(Zn, {
    defaultVisitor: u,
    convertValue: l,
    isVisitable: We
  });
  function $(f, h) {
    if (!d.isUndefined(f)) {
      if (g.indexOf(f) !== -1)
        throw Error("Circular reference detected in " + h.join("."));
      g.push(f), d.forEach(f, function(C, L) {
        (!(d.isUndefined(C) || C === null) && r.call(t, C, d.isString(L) ? L.trim() : L, h, v)) === !0 && $(C, h ? h.concat(L) : [L]);
      }), g.pop();
    }
  }
  if (!d.isObject(e))
    throw new TypeError("data must be an object");
  return $(e), t;
}
function dt(e) {
  const t = {
    "!": "%21",
    "'": "%27",
    "(": "%28",
    ")": "%29",
    "~": "%7E",
    "%20": "+",
    "%00": "\0"
  };
  return encodeURIComponent(e).replace(/[!'()~]|%20|%00/g, function(s) {
    return t[s];
  });
}
function nt(e, t) {
  this._pairs = [], e && je(e, this, t);
}
const Mt = nt.prototype;
Mt.append = function(t, n) {
  this._pairs.push([t, n]);
};
Mt.toString = function(t) {
  const n = t ? function(s) {
    return t.call(this, s, dt);
  } : dt;
  return this._pairs.map(function(r) {
    return n(r[0]) + "=" + n(r[1]);
  }, "").join("&");
};
function es(e) {
  return encodeURIComponent(e).replace(/%3A/gi, ":").replace(/%24/g, "$").replace(/%2C/gi, ",").replace(/%20/g, "+");
}
function It(e, t, n) {
  if (!t)
    return e;
  const s = n && n.encode || es, r = d.isFunction(n) ? {
    serialize: n
  } : n, o = r && r.serialize;
  let a;
  if (o ? a = o(t, r) : a = d.isURLSearchParams(t) ? t.toString() : new nt(t, r).toString(s), a) {
    const c = e.indexOf("#");
    c !== -1 && (e = e.slice(0, c)), e += (e.indexOf("?") === -1 ? "?" : "&") + a;
  }
  return e;
}
class ut {
  constructor() {
    this.handlers = [];
  }
  /**
   * Add a new interceptor to the stack
   *
   * @param {Function} fulfilled The function to handle `then` for a `Promise`
   * @param {Function} rejected The function to handle `reject` for a `Promise`
   * @param {Object} options The options for the interceptor, synchronous and runWhen
   *
   * @return {Number} An ID used to remove interceptor later
   */
  use(t, n, s) {
    return this.handlers.push({
      fulfilled: t,
      rejected: n,
      synchronous: s ? s.synchronous : !1,
      runWhen: s ? s.runWhen : null
    }), this.handlers.length - 1;
  }
  /**
   * Remove an interceptor from the stack
   *
   * @param {Number} id The ID that was returned by `use`
   *
   * @returns {void}
   */
  eject(t) {
    this.handlers[t] && (this.handlers[t] = null);
  }
  /**
   * Clear all interceptors from the stack
   *
   * @returns {void}
   */
  clear() {
    this.handlers && (this.handlers = []);
  }
  /**
   * Iterate over all the registered interceptors
   *
   * This method is particularly useful for skipping over any
   * interceptors that may have become `null` calling `eject`.
   *
   * @param {Function} fn The function to call for each interceptor
   *
   * @returns {void}
   */
  forEach(t) {
    d.forEach(this.handlers, function(s) {
      s !== null && t(s);
    });
  }
}
const st = {
  silentJSONParsing: !0,
  forcedJSONParsing: !0,
  clarifyTimeoutError: !1,
  legacyInterceptorReqResOrdering: !0
}, ts = typeof URLSearchParams < "u" ? URLSearchParams : nt, ns = typeof FormData < "u" ? FormData : null, ss = typeof Blob < "u" ? Blob : null, rs = {
  isBrowser: !0,
  classes: {
    URLSearchParams: ts,
    FormData: ns,
    Blob: ss
  },
  protocols: ["http", "https", "file", "blob", "url", "data"]
}, rt = typeof window < "u" && typeof document < "u", Ge = typeof navigator == "object" && navigator || void 0, os = rt && (!Ge || ["ReactNative", "NativeScript", "NS"].indexOf(Ge.product) < 0), as = typeof WorkerGlobalScope < "u" && // eslint-disable-next-line no-undef
self instanceof WorkerGlobalScope && typeof self.importScripts == "function", is = rt && window.location.href || "http://localhost", ls = /* @__PURE__ */ Object.freeze(/* @__PURE__ */ Object.defineProperty({
  __proto__: null,
  hasBrowserEnv: rt,
  hasStandardBrowserEnv: os,
  hasStandardBrowserWebWorkerEnv: as,
  navigator: Ge,
  origin: is
}, Symbol.toStringTag, { value: "Module" })), K = {
  ...ls,
  ...rs
};
function cs(e, t) {
  return je(e, new K.classes.URLSearchParams(), {
    visitor: function(n, s, r, o) {
      return K.isNode && d.isBuffer(n) ? (this.append(s, n.toString("base64")), !1) : o.defaultVisitor.apply(this, arguments);
    },
    ...t
  });
}
function ds(e) {
  return d.matchAll(/\w+|\[(\w*)]/g, e).map((t) => t[0] === "[]" ? "" : t[1] || t[0]);
}
function us(e) {
  const t = {}, n = Object.keys(e);
  let s;
  const r = n.length;
  let o;
  for (s = 0; s < r; s++)
    o = n[s], t[o] = e[o];
  return t;
}
function Dt(e) {
  function t(n, s, r, o) {
    let a = n[o++];
    if (a === "__proto__") return !0;
    const c = Number.isFinite(+a), m = o >= n.length;
    return a = !a && d.isArray(r) ? r.length : a, m ? (d.hasOwnProp(r, a) ? r[a] = [r[a], s] : r[a] = s, !c) : ((!r[a] || !d.isObject(r[a])) && (r[a] = []), t(n, s, r[a], o) && d.isArray(r[a]) && (r[a] = us(r[a])), !c);
  }
  if (d.isFormData(e) && d.isFunction(e.entries)) {
    const n = {};
    return d.forEachEntry(e, (s, r) => {
      t(ds(s), r, n, 0);
    }), n;
  }
  return null;
}
function fs(e, t, n) {
  if (d.isString(e))
    try {
      return (t || JSON.parse)(e), d.trim(e);
    } catch (s) {
      if (s.name !== "SyntaxError")
        throw s;
    }
  return (n || JSON.stringify)(e);
}
const ze = {
  transitional: st,
  adapter: ["xhr", "http", "fetch"],
  transformRequest: [
    function(t, n) {
      const s = n.getContentType() || "", r = s.indexOf("application/json") > -1, o = d.isObject(t);
      if (o && d.isHTMLForm(t) && (t = new FormData(t)), d.isFormData(t))
        return r ? JSON.stringify(Dt(t)) : t;
      if (d.isArrayBuffer(t) || d.isBuffer(t) || d.isStream(t) || d.isFile(t) || d.isBlob(t) || d.isReadableStream(t))
        return t;
      if (d.isArrayBufferView(t))
        return t.buffer;
      if (d.isURLSearchParams(t))
        return n.setContentType("application/x-www-form-urlencoded;charset=utf-8", !1), t.toString();
      let c;
      if (o) {
        if (s.indexOf("application/x-www-form-urlencoded") > -1)
          return cs(t, this.formSerializer).toString();
        if ((c = d.isFileList(t)) || s.indexOf("multipart/form-data") > -1) {
          const m = this.env && this.env.FormData;
          return je(
            c ? { "files[]": t } : t,
            m && new m(),
            this.formSerializer
          );
        }
      }
      return o || r ? (n.setContentType("application/json", !1), fs(t)) : t;
    }
  ],
  transformResponse: [
    function(t) {
      const n = this.transitional || ze.transitional, s = n && n.forcedJSONParsing, r = this.responseType === "json";
      if (d.isResponse(t) || d.isReadableStream(t))
        return t;
      if (t && d.isString(t) && (s && !this.responseType || r)) {
        const a = !(n && n.silentJSONParsing) && r;
        try {
          return JSON.parse(t, this.parseReviver);
        } catch (c) {
          if (a)
            throw c.name === "SyntaxError" ? z.from(c, z.ERR_BAD_RESPONSE, this, null, this.response) : c;
        }
      }
      return t;
    }
  ],
  /**
   * A timeout in milliseconds to abort a request. If set to 0 (default) a
   * timeout is not created.
   */
  timeout: 0,
  xsrfCookieName: "XSRF-TOKEN",
  xsrfHeaderName: "X-XSRF-TOKEN",
  maxContentLength: -1,
  maxBodyLength: -1,
  env: {
    FormData: K.classes.FormData,
    Blob: K.classes.Blob
  },
  validateStatus: function(t) {
    return t >= 200 && t < 300;
  },
  headers: {
    common: {
      Accept: "application/json, text/plain, */*",
      "Content-Type": void 0
    }
  }
};
d.forEach(["delete", "get", "head", "post", "put", "patch"], (e) => {
  ze.headers[e] = {};
});
const ps = d.toObjectSet([
  "age",
  "authorization",
  "content-length",
  "content-type",
  "etag",
  "expires",
  "from",
  "host",
  "if-modified-since",
  "if-unmodified-since",
  "last-modified",
  "location",
  "max-forwards",
  "proxy-authorization",
  "referer",
  "retry-after",
  "user-agent"
]), ms = (e) => {
  const t = {};
  let n, s, r;
  return e && e.split(`
`).forEach(function(a) {
    r = a.indexOf(":"), n = a.substring(0, r).trim().toLowerCase(), s = a.substring(r + 1).trim(), !(!n || t[n] && ps[n]) && (n === "set-cookie" ? t[n] ? t[n].push(s) : t[n] = [s] : t[n] = t[n] ? t[n] + ", " + s : s);
  }), t;
}, ft = /* @__PURE__ */ Symbol("internals"), hs = (e) => !/[\r\n]/.test(e);
function Bt(e, t) {
  if (!(e === !1 || e == null)) {
    if (d.isArray(e)) {
      e.forEach((n) => Bt(n, t));
      return;
    }
    if (!hs(String(e)))
      throw new Error(`Invalid character in header content ["${t}"]`);
  }
}
function xe(e) {
  return e && String(e).trim().toLowerCase();
}
function gs(e) {
  let t = e.length;
  for (; t > 0; ) {
    const n = e.charCodeAt(t - 1);
    if (n !== 10 && n !== 13)
      break;
    t -= 1;
  }
  return t === e.length ? e : e.slice(0, t);
}
function Oe(e) {
  return e === !1 || e == null ? e : d.isArray(e) ? e.map(Oe) : gs(String(e));
}
function bs(e) {
  const t = /* @__PURE__ */ Object.create(null), n = /([^\s,;=]+)\s*(?:=\s*([^,;]+))?/g;
  let s;
  for (; s = n.exec(e); )
    t[s[1]] = s[2];
  return t;
}
const xs = (e) => /^[-_a-zA-Z0-9^`|~,!#$%&'*+.]+$/.test(e.trim());
function Be(e, t, n, s, r) {
  if (d.isFunction(s))
    return s.call(this, t, n);
  if (r && (t = n), !!d.isString(t)) {
    if (d.isString(s))
      return t.indexOf(s) !== -1;
    if (d.isRegExp(s))
      return s.test(t);
  }
}
function vs(e) {
  return e.trim().toLowerCase().replace(/([a-z\d])(\w*)/g, (t, n, s) => n.toUpperCase() + s);
}
function ys(e, t) {
  const n = d.toCamelCase(" " + t);
  ["get", "set", "has"].forEach((s) => {
    Object.defineProperty(e, s + n, {
      value: function(r, o, a) {
        return this[s].call(this, t, r, o, a);
      },
      configurable: !0
    });
  });
}
let X = class {
  constructor(t) {
    t && this.set(t);
  }
  set(t, n, s) {
    const r = this;
    function o(c, m, l) {
      const u = xe(m);
      if (!u)
        throw new Error("header name must be a non-empty string");
      const g = d.findKey(r, u);
      (!g || r[g] === void 0 || l === !0 || l === void 0 && r[g] !== !1) && (Bt(c, m), r[g || m] = Oe(c));
    }
    const a = (c, m) => d.forEach(c, (l, u) => o(l, u, m));
    if (d.isPlainObject(t) || t instanceof this.constructor)
      a(t, n);
    else if (d.isString(t) && (t = t.trim()) && !xs(t))
      a(ms(t), n);
    else if (d.isObject(t) && d.isIterable(t)) {
      let c = {}, m, l;
      for (const u of t) {
        if (!d.isArray(u))
          throw TypeError("Object iterator must return a key-value pair");
        c[l = u[0]] = (m = c[l]) ? d.isArray(m) ? [...m, u[1]] : [m, u[1]] : u[1];
      }
      a(c, n);
    } else
      t != null && o(n, t, s);
    return this;
  }
  get(t, n) {
    if (t = xe(t), t) {
      const s = d.findKey(this, t);
      if (s) {
        const r = this[s];
        if (!n)
          return r;
        if (n === !0)
          return bs(r);
        if (d.isFunction(n))
          return n.call(this, r, s);
        if (d.isRegExp(n))
          return n.exec(r);
        throw new TypeError("parser must be boolean|regexp|function");
      }
    }
  }
  has(t, n) {
    if (t = xe(t), t) {
      const s = d.findKey(this, t);
      return !!(s && this[s] !== void 0 && (!n || Be(this, this[s], s, n)));
    }
    return !1;
  }
  delete(t, n) {
    const s = this;
    let r = !1;
    function o(a) {
      if (a = xe(a), a) {
        const c = d.findKey(s, a);
        c && (!n || Be(s, s[c], c, n)) && (delete s[c], r = !0);
      }
    }
    return d.isArray(t) ? t.forEach(o) : o(t), r;
  }
  clear(t) {
    const n = Object.keys(this);
    let s = n.length, r = !1;
    for (; s--; ) {
      const o = n[s];
      (!t || Be(this, this[o], o, t, !0)) && (delete this[o], r = !0);
    }
    return r;
  }
  normalize(t) {
    const n = this, s = {};
    return d.forEach(this, (r, o) => {
      const a = d.findKey(s, o);
      if (a) {
        n[a] = Oe(r), delete n[o];
        return;
      }
      const c = t ? vs(o) : String(o).trim();
      c !== o && delete n[o], n[c] = Oe(r), s[c] = !0;
    }), this;
  }
  concat(...t) {
    return this.constructor.concat(this, ...t);
  }
  toJSON(t) {
    const n = /* @__PURE__ */ Object.create(null);
    return d.forEach(this, (s, r) => {
      s != null && s !== !1 && (n[r] = t && d.isArray(s) ? s.join(", ") : s);
    }), n;
  }
  [Symbol.iterator]() {
    return Object.entries(this.toJSON())[Symbol.iterator]();
  }
  toString() {
    return Object.entries(this.toJSON()).map(([t, n]) => t + ": " + n).join(`
`);
  }
  getSetCookie() {
    return this.get("set-cookie") || [];
  }
  get [Symbol.toStringTag]() {
    return "AxiosHeaders";
  }
  static from(t) {
    return t instanceof this ? t : new this(t);
  }
  static concat(t, ...n) {
    const s = new this(t);
    return n.forEach((r) => s.set(r)), s;
  }
  static accessor(t) {
    const s = (this[ft] = this[ft] = {
      accessors: {}
    }).accessors, r = this.prototype;
    function o(a) {
      const c = xe(a);
      s[c] || (ys(r, a), s[c] = !0);
    }
    return d.isArray(t) ? t.forEach(o) : o(t), this;
  }
};
X.accessor([
  "Content-Type",
  "Content-Length",
  "Accept",
  "Accept-Encoding",
  "User-Agent",
  "Authorization"
]);
d.reduceDescriptors(X.prototype, ({ value: e }, t) => {
  let n = t[0].toUpperCase() + t.slice(1);
  return {
    get: () => e,
    set(s) {
      this[n] = s;
    }
  };
});
d.freezeMethods(X);
function qe(e, t) {
  const n = this || ze, s = t || n, r = X.from(s.headers);
  let o = s.data;
  return d.forEach(e, function(c) {
    o = c.call(n, o, r.normalize(), t ? t.status : void 0);
  }), r.normalize(), o;
}
function qt(e) {
  return !!(e && e.__CANCEL__);
}
let Re = class extends z {
  /**
   * A `CanceledError` is an object that is thrown when an operation is canceled.
   *
   * @param {string=} message The message.
   * @param {Object=} config The config.
   * @param {Object=} request The request.
   *
   * @returns {CanceledError} The created error.
   */
  constructor(t, n, s) {
    super(t ?? "canceled", z.ERR_CANCELED, n, s), this.name = "CanceledError", this.__CANCEL__ = !0;
  }
};
function Ht(e, t, n) {
  const s = n.config.validateStatus;
  !n.status || !s || s(n.status) ? e(n) : t(
    new z(
      "Request failed with status code " + n.status,
      [z.ERR_BAD_REQUEST, z.ERR_BAD_RESPONSE][Math.floor(n.status / 100) - 4],
      n.config,
      n.request,
      n
    )
  );
}
function ws(e) {
  const t = /^([-+\w]{1,25})(:?\/\/|:)/.exec(e);
  return t && t[1] || "";
}
function ks(e, t) {
  e = e || 10;
  const n = new Array(e), s = new Array(e);
  let r = 0, o = 0, a;
  return t = t !== void 0 ? t : 1e3, function(m) {
    const l = Date.now(), u = s[o];
    a || (a = l), n[r] = m, s[r] = l;
    let g = o, v = 0;
    for (; g !== r; )
      v += n[g++], g = g % e;
    if (r = (r + 1) % e, r === o && (o = (o + 1) % e), l - a < t)
      return;
    const $ = u && l - u;
    return $ ? Math.round(v * 1e3 / $) : void 0;
  };
}
function _s(e, t) {
  let n = 0, s = 1e3 / t, r, o;
  const a = (l, u = Date.now()) => {
    n = u, r = null, o && (clearTimeout(o), o = null), e(...l);
  };
  return [(...l) => {
    const u = Date.now(), g = u - n;
    g >= s ? a(l, u) : (r = l, o || (o = setTimeout(() => {
      o = null, a(r);
    }, s - g)));
  }, () => r && a(r)];
}
const $e = (e, t, n = 3) => {
  let s = 0;
  const r = ks(50, 250);
  return _s((o) => {
    const a = o.loaded, c = o.lengthComputable ? o.total : void 0, m = a - s, l = r(m), u = a <= c;
    s = a;
    const g = {
      loaded: a,
      total: c,
      progress: c ? a / c : void 0,
      bytes: m,
      rate: l || void 0,
      estimated: l && c && u ? (c - a) / l : void 0,
      event: o,
      lengthComputable: c != null,
      [t ? "download" : "upload"]: !0
    };
    e(g);
  }, n);
}, pt = (e, t) => {
  const n = e != null;
  return [
    (s) => t[0]({
      lengthComputable: n,
      total: e,
      loaded: s
    }),
    t[1]
  ];
}, mt = (e) => (...t) => d.asap(() => e(...t)), zs = K.hasStandardBrowserEnv ? /* @__PURE__ */ ((e, t) => (n) => (n = new URL(n, K.origin), e.protocol === n.protocol && e.host === n.host && (t || e.port === n.port)))(
  new URL(K.origin),
  K.navigator && /(msie|trident)/i.test(K.navigator.userAgent)
) : () => !0, Rs = K.hasStandardBrowserEnv ? (
  // Standard browser envs support document.cookie
  {
    write(e, t, n, s, r, o, a) {
      if (typeof document > "u") return;
      const c = [`${e}=${encodeURIComponent(t)}`];
      d.isNumber(n) && c.push(`expires=${new Date(n).toUTCString()}`), d.isString(s) && c.push(`path=${s}`), d.isString(r) && c.push(`domain=${r}`), o === !0 && c.push("secure"), d.isString(a) && c.push(`SameSite=${a}`), document.cookie = c.join("; ");
    },
    read(e) {
      if (typeof document > "u") return null;
      const t = document.cookie.match(new RegExp("(?:^|; )" + e + "=([^;]*)"));
      return t ? decodeURIComponent(t[1]) : null;
    },
    remove(e) {
      this.write(e, "", Date.now() - 864e5, "/");
    }
  }
) : (
  // Non-standard browser env (web workers, react-native) lack needed support.
  {
    write() {
    },
    read() {
      return null;
    },
    remove() {
    }
  }
);
function Es(e) {
  return typeof e != "string" ? !1 : /^([a-z][a-z\d+\-.]*:)?\/\//i.test(e);
}
function Cs(e, t) {
  return t ? e.replace(/\/?\/$/, "") + "/" + t.replace(/^\/+/, "") : e;
}
function Vt(e, t, n) {
  let s = !Es(t);
  return e && (s || n == !1) ? Cs(e, t) : t;
}
const ht = (e) => e instanceof X ? { ...e } : e;
function pe(e, t) {
  t = t || {};
  const n = {};
  function s(l, u, g, v) {
    return d.isPlainObject(l) && d.isPlainObject(u) ? d.merge.call({ caseless: v }, l, u) : d.isPlainObject(u) ? d.merge({}, u) : d.isArray(u) ? u.slice() : u;
  }
  function r(l, u, g, v) {
    if (d.isUndefined(u)) {
      if (!d.isUndefined(l))
        return s(void 0, l, g, v);
    } else return s(l, u, g, v);
  }
  function o(l, u) {
    if (!d.isUndefined(u))
      return s(void 0, u);
  }
  function a(l, u) {
    if (d.isUndefined(u)) {
      if (!d.isUndefined(l))
        return s(void 0, l);
    } else return s(void 0, u);
  }
  function c(l, u, g) {
    if (g in t)
      return s(l, u);
    if (g in e)
      return s(void 0, l);
  }
  const m = {
    url: o,
    method: o,
    data: o,
    baseURL: a,
    transformRequest: a,
    transformResponse: a,
    paramsSerializer: a,
    timeout: a,
    timeoutMessage: a,
    withCredentials: a,
    withXSRFToken: a,
    adapter: a,
    responseType: a,
    xsrfCookieName: a,
    xsrfHeaderName: a,
    onUploadProgress: a,
    onDownloadProgress: a,
    decompress: a,
    maxContentLength: a,
    maxBodyLength: a,
    beforeRedirect: a,
    transport: a,
    httpAgent: a,
    httpsAgent: a,
    cancelToken: a,
    socketPath: a,
    responseEncoding: a,
    validateStatus: c,
    headers: (l, u, g) => r(ht(l), ht(u), g, !0)
  };
  return d.forEach(Object.keys({ ...e, ...t }), function(u) {
    if (u === "__proto__" || u === "constructor" || u === "prototype") return;
    const g = d.hasOwnProp(m, u) ? m[u] : r, v = g(e[u], t[u], u);
    d.isUndefined(v) && g !== c || (n[u] = v);
  }), n;
}
const Jt = (e) => {
  const t = pe({}, e);
  let { data: n, withXSRFToken: s, xsrfHeaderName: r, xsrfCookieName: o, headers: a, auth: c } = t;
  if (t.headers = a = X.from(a), t.url = It(
    Vt(t.baseURL, t.url, t.allowAbsoluteUrls),
    e.params,
    e.paramsSerializer
  ), c && a.set(
    "Authorization",
    "Basic " + btoa(
      (c.username || "") + ":" + (c.password ? unescape(encodeURIComponent(c.password)) : "")
    )
  ), d.isFormData(n)) {
    if (K.hasStandardBrowserEnv || K.hasStandardBrowserWebWorkerEnv)
      a.setContentType(void 0);
    else if (d.isFunction(n.getHeaders)) {
      const m = n.getHeaders(), l = ["content-type", "content-length"];
      Object.entries(m).forEach(([u, g]) => {
        l.includes(u.toLowerCase()) && a.set(u, g);
      });
    }
  }
  if (K.hasStandardBrowserEnv && (s && d.isFunction(s) && (s = s(t)), s || s !== !1 && zs(t.url))) {
    const m = r && o && Rs.read(o);
    m && a.set(r, m);
  }
  return t;
}, Ss = typeof XMLHttpRequest < "u", As = Ss && function(e) {
  return new Promise(function(n, s) {
    const r = Jt(e);
    let o = r.data;
    const a = X.from(r.headers).normalize();
    let { responseType: c, onUploadProgress: m, onDownloadProgress: l } = r, u, g, v, $, f;
    function h() {
      $ && $(), f && f(), r.cancelToken && r.cancelToken.unsubscribe(u), r.signal && r.signal.removeEventListener("abort", u);
    }
    let b = new XMLHttpRequest();
    b.open(r.method.toUpperCase(), r.url, !0), b.timeout = r.timeout;
    function C() {
      if (!b)
        return;
      const U = X.from(
        "getAllResponseHeaders" in b && b.getAllResponseHeaders()
      ), W = {
        data: !c || c === "text" || c === "json" ? b.responseText : b.response,
        status: b.status,
        statusText: b.statusText,
        headers: U,
        config: e,
        request: b
      };
      Ht(
        function(M) {
          n(M), h();
        },
        function(M) {
          s(M), h();
        },
        W
      ), b = null;
    }
    "onloadend" in b ? b.onloadend = C : b.onreadystatechange = function() {
      !b || b.readyState !== 4 || b.status === 0 && !(b.responseURL && b.responseURL.indexOf("file:") === 0) || setTimeout(C);
    }, b.onabort = function() {
      b && (s(new z("Request aborted", z.ECONNABORTED, e, b)), b = null);
    }, b.onerror = function(q) {
      const W = q && q.message ? q.message : "Network Error", se = new z(W, z.ERR_NETWORK, e, b);
      se.event = q || null, s(se), b = null;
    }, b.ontimeout = function() {
      let q = r.timeout ? "timeout of " + r.timeout + "ms exceeded" : "timeout exceeded";
      const W = r.transitional || st;
      r.timeoutErrorMessage && (q = r.timeoutErrorMessage), s(
        new z(
          q,
          W.clarifyTimeoutError ? z.ETIMEDOUT : z.ECONNABORTED,
          e,
          b
        )
      ), b = null;
    }, o === void 0 && a.setContentType(null), "setRequestHeader" in b && d.forEach(a.toJSON(), function(q, W) {
      b.setRequestHeader(W, q);
    }), d.isUndefined(r.withCredentials) || (b.withCredentials = !!r.withCredentials), c && c !== "json" && (b.responseType = r.responseType), l && ([v, f] = $e(l, !0), b.addEventListener("progress", v)), m && b.upload && ([g, $] = $e(m), b.upload.addEventListener("progress", g), b.upload.addEventListener("loadend", $)), (r.cancelToken || r.signal) && (u = (U) => {
      b && (s(!U || U.type ? new Re(null, e, b) : U), b.abort(), b = null);
    }, r.cancelToken && r.cancelToken.subscribe(u), r.signal && (r.signal.aborted ? u() : r.signal.addEventListener("abort", u)));
    const L = ws(r.url);
    if (L && K.protocols.indexOf(L) === -1) {
      s(
        new z(
          "Unsupported protocol " + L + ":",
          z.ERR_BAD_REQUEST,
          e
        )
      );
      return;
    }
    b.send(o || null);
  });
}, Os = (e, t) => {
  const { length: n } = e = e ? e.filter(Boolean) : [];
  if (t || n) {
    let s = new AbortController(), r;
    const o = function(l) {
      if (!r) {
        r = !0, c();
        const u = l instanceof Error ? l : this.reason;
        s.abort(
          u instanceof z ? u : new Re(u instanceof Error ? u.message : u)
        );
      }
    };
    let a = t && setTimeout(() => {
      a = null, o(new z(`timeout of ${t}ms exceeded`, z.ETIMEDOUT));
    }, t);
    const c = () => {
      e && (a && clearTimeout(a), a = null, e.forEach((l) => {
        l.unsubscribe ? l.unsubscribe(o) : l.removeEventListener("abort", o);
      }), e = null);
    };
    e.forEach((l) => l.addEventListener("abort", o));
    const { signal: m } = s;
    return m.unsubscribe = () => d.asap(c), m;
  }
}, Ts = function* (e, t) {
  let n = e.byteLength;
  if (n < t) {
    yield e;
    return;
  }
  let s = 0, r;
  for (; s < n; )
    r = s + t, yield e.slice(s, r), s = r;
}, $s = async function* (e, t) {
  for await (const n of Ns(e))
    yield* Ts(n, t);
}, Ns = async function* (e) {
  if (e[Symbol.asyncIterator]) {
    yield* e;
    return;
  }
  const t = e.getReader();
  try {
    for (; ; ) {
      const { done: n, value: s } = await t.read();
      if (n)
        break;
      yield s;
    }
  } finally {
    await t.cancel();
  }
}, gt = (e, t, n, s) => {
  const r = $s(e, t);
  let o = 0, a, c = (m) => {
    a || (a = !0, s && s(m));
  };
  return new ReadableStream(
    {
      async pull(m) {
        try {
          const { done: l, value: u } = await r.next();
          if (l) {
            c(), m.close();
            return;
          }
          let g = u.byteLength;
          if (n) {
            let v = o += g;
            n(v);
          }
          m.enqueue(new Uint8Array(u));
        } catch (l) {
          throw c(l), l;
        }
      },
      cancel(m) {
        return c(m), r.return();
      }
    },
    {
      highWaterMark: 2
    }
  );
}, bt = 64 * 1024, { isFunction: Se } = d, Ps = (({ Request: e, Response: t }) => ({
  Request: e,
  Response: t
}))(d.global), { ReadableStream: xt, TextEncoder: vt } = d.global, yt = (e, ...t) => {
  try {
    return !!e(...t);
  } catch {
    return !1;
  }
}, Us = (e) => {
  e = d.merge.call(
    {
      skipUndefined: !0
    },
    Ps,
    e
  );
  const { fetch: t, Request: n, Response: s } = e, r = t ? Se(t) : typeof fetch == "function", o = Se(n), a = Se(s);
  if (!r)
    return !1;
  const c = r && Se(xt), m = r && (typeof vt == "function" ? /* @__PURE__ */ ((f) => (h) => f.encode(h))(new vt()) : async (f) => new Uint8Array(await new n(f).arrayBuffer())), l = o && c && yt(() => {
    let f = !1;
    const h = new xt(), b = new n(K.origin, {
      body: h,
      method: "POST",
      get duplex() {
        return f = !0, "half";
      }
    }).headers.has("Content-Type");
    return h.cancel(), f && !b;
  }), u = a && c && yt(() => d.isReadableStream(new s("").body)), g = {
    stream: u && ((f) => f.body)
  };
  r && ["text", "arrayBuffer", "blob", "formData", "stream"].forEach((f) => {
    !g[f] && (g[f] = (h, b) => {
      let C = h && h[f];
      if (C)
        return C.call(h);
      throw new z(
        `Response type '${f}' is not supported`,
        z.ERR_NOT_SUPPORT,
        b
      );
    });
  });
  const v = async (f) => {
    if (f == null)
      return 0;
    if (d.isBlob(f))
      return f.size;
    if (d.isSpecCompliantForm(f))
      return (await new n(K.origin, {
        method: "POST",
        body: f
      }).arrayBuffer()).byteLength;
    if (d.isArrayBufferView(f) || d.isArrayBuffer(f))
      return f.byteLength;
    if (d.isURLSearchParams(f) && (f = f + ""), d.isString(f))
      return (await m(f)).byteLength;
  }, $ = async (f, h) => {
    const b = d.toFiniteNumber(f.getContentLength());
    return b ?? v(h);
  };
  return async (f) => {
    let {
      url: h,
      method: b,
      data: C,
      signal: L,
      cancelToken: U,
      timeout: q,
      onDownloadProgress: W,
      onUploadProgress: se,
      responseType: M,
      headers: ie,
      withCredentials: ce = "same-origin",
      fetchOptions: A
    } = Jt(f), p = t || fetch;
    M = M ? (M + "").toLowerCase() : "text";
    let k = Os(
      [L, U && U.toAbortSignal()],
      q
    ), V = null;
    const le = k && k.unsubscribe && (() => {
      k.unsubscribe();
    });
    let me;
    try {
      if (se && l && b !== "get" && b !== "head" && (me = await $(ie, C)) !== 0) {
        let R = new n(h, {
          method: "POST",
          body: C,
          duplex: "half"
        }), _;
        if (d.isFormData(C) && (_ = R.headers.get("content-type")) && ie.setContentType(_), R.body) {
          const [E, F] = pt(
            me,
            $e(mt(se))
          );
          C = gt(R.body, bt, E, F);
        }
      }
      d.isString(ce) || (ce = ce ? "include" : "omit");
      const J = o && "credentials" in n.prototype, Ee = {
        ...A,
        signal: k,
        method: b.toUpperCase(),
        headers: ie.normalize().toJSON(),
        body: C,
        duplex: "half",
        credentials: J ? ce : void 0
      };
      V = o && new n(h, Ee);
      let re = await (o ? p(V, A) : p(h, Ee));
      const Ce = u && (M === "stream" || M === "response");
      if (u && (W || Ce && le)) {
        const R = {};
        ["status", "statusText", "headers"].forEach((Q) => {
          R[Q] = re[Q];
        });
        const _ = d.toFiniteNumber(re.headers.get("content-length")), [E, F] = W && pt(
          _,
          $e(mt(W), !0)
        ) || [];
        re = new s(
          gt(re.body, bt, E, () => {
            F && F(), le && le();
          }),
          R
        );
      }
      M = M || "text";
      let Ie = await g[d.findKey(g, M) || "text"](
        re,
        f
      );
      return !Ce && le && le(), await new Promise((R, _) => {
        Ht(R, _, {
          data: Ie,
          headers: X.from(re.headers),
          status: re.status,
          statusText: re.statusText,
          config: f,
          request: V
        });
      });
    } catch (J) {
      throw le && le(), J && J.name === "TypeError" && /Load failed|fetch/i.test(J.message) ? Object.assign(
        new z(
          "Network Error",
          z.ERR_NETWORK,
          f,
          V,
          J && J.response
        ),
        {
          cause: J.cause || J
        }
      ) : z.from(J, J && J.code, f, V, J && J.response);
    }
  };
}, Fs = /* @__PURE__ */ new Map(), Kt = (e) => {
  let t = e && e.env || {};
  const { fetch: n, Request: s, Response: r } = t, o = [s, r, n];
  let a = o.length, c = a, m, l, u = Fs;
  for (; c--; )
    m = o[c], l = u.get(m), l === void 0 && u.set(m, l = c ? /* @__PURE__ */ new Map() : Us(t)), u = l;
  return l;
};
Kt();
const ot = {
  http: Qn,
  xhr: As,
  fetch: {
    get: Kt
  }
};
d.forEach(ot, (e, t) => {
  if (e) {
    try {
      Object.defineProperty(e, "name", { value: t });
    } catch {
    }
    Object.defineProperty(e, "adapterName", { value: t });
  }
});
const wt = (e) => `- ${e}`, Ls = (e) => d.isFunction(e) || e === null || e === !1;
function js(e, t) {
  e = d.isArray(e) ? e : [e];
  const { length: n } = e;
  let s, r;
  const o = {};
  for (let a = 0; a < n; a++) {
    s = e[a];
    let c;
    if (r = s, !Ls(s) && (r = ot[(c = String(s)).toLowerCase()], r === void 0))
      throw new z(`Unknown adapter '${c}'`);
    if (r && (d.isFunction(r) || (r = r.get(t))))
      break;
    o[c || "#" + a] = r;
  }
  if (!r) {
    const a = Object.entries(o).map(
      ([m, l]) => `adapter ${m} ` + (l === !1 ? "is not supported by the environment" : "is not available in the build")
    );
    let c = n ? a.length > 1 ? `since :
` + a.map(wt).join(`
`) : " " + wt(a[0]) : "as no adapter specified";
    throw new z(
      "There is no suitable adapter to dispatch the request " + c,
      "ERR_NOT_SUPPORT"
    );
  }
  return r;
}
const Wt = {
  /**
   * Resolve an adapter from a list of adapter names or functions.
   * @type {Function}
   */
  getAdapter: js,
  /**
   * Exposes all known adapters
   * @type {Object<string, Function|Object>}
   */
  adapters: ot
};
function He(e) {
  if (e.cancelToken && e.cancelToken.throwIfRequested(), e.signal && e.signal.aborted)
    throw new Re(null, e);
}
function kt(e) {
  return He(e), e.headers = X.from(e.headers), e.data = qe.call(e, e.transformRequest), ["post", "put", "patch"].indexOf(e.method) !== -1 && e.headers.setContentType("application/x-www-form-urlencoded", !1), Wt.getAdapter(e.adapter || ze.adapter, e)(e).then(
    function(s) {
      return He(e), s.data = qe.call(e, e.transformResponse, s), s.headers = X.from(s.headers), s;
    },
    function(s) {
      return qt(s) || (He(e), s && s.response && (s.response.data = qe.call(
        e,
        e.transformResponse,
        s.response
      ), s.response.headers = X.from(s.response.headers))), Promise.reject(s);
    }
  );
}
const Gt = "1.15.0", Me = {};
["object", "boolean", "number", "function", "string", "symbol"].forEach((e, t) => {
  Me[e] = function(s) {
    return typeof s === e || "a" + (t < 1 ? "n " : " ") + e;
  };
});
const _t = {};
Me.transitional = function(t, n, s) {
  function r(o, a) {
    return "[Axios v" + Gt + "] Transitional option '" + o + "'" + a + (s ? ". " + s : "");
  }
  return (o, a, c) => {
    if (t === !1)
      throw new z(
        r(a, " has been removed" + (n ? " in " + n : "")),
        z.ERR_DEPRECATED
      );
    return n && !_t[a] && (_t[a] = !0, console.warn(
      r(
        a,
        " has been deprecated since v" + n + " and will be removed in the near future"
      )
    )), t ? t(o, a, c) : !0;
  };
};
Me.spelling = function(t) {
  return (n, s) => (console.warn(`${s} is likely a misspelling of ${t}`), !0);
};
function Ms(e, t, n) {
  if (typeof e != "object")
    throw new z("options must be an object", z.ERR_BAD_OPTION_VALUE);
  const s = Object.keys(e);
  let r = s.length;
  for (; r-- > 0; ) {
    const o = s[r], a = t[o];
    if (a) {
      const c = e[o], m = c === void 0 || a(c, o, e);
      if (m !== !0)
        throw new z(
          "option " + o + " must be " + m,
          z.ERR_BAD_OPTION_VALUE
        );
      continue;
    }
    if (n !== !0)
      throw new z("Unknown option " + o, z.ERR_BAD_OPTION);
  }
}
const Te = {
  assertOptions: Ms,
  validators: Me
}, ee = Te.validators;
let fe = class {
  constructor(t) {
    this.defaults = t || {}, this.interceptors = {
      request: new ut(),
      response: new ut()
    };
  }
  /**
   * Dispatch a request
   *
   * @param {String|Object} configOrUrl The config specific for this request (merged with this.defaults)
   * @param {?Object} config
   *
   * @returns {Promise} The Promise to be fulfilled
   */
  async request(t, n) {
    try {
      return await this._request(t, n);
    } catch (s) {
      if (s instanceof Error) {
        let r = {};
        Error.captureStackTrace ? Error.captureStackTrace(r) : r = new Error();
        const o = (() => {
          if (!r.stack)
            return "";
          const a = r.stack.indexOf(`
`);
          return a === -1 ? "" : r.stack.slice(a + 1);
        })();
        try {
          if (!s.stack)
            s.stack = o;
          else if (o) {
            const a = o.indexOf(`
`), c = a === -1 ? -1 : o.indexOf(`
`, a + 1), m = c === -1 ? "" : o.slice(c + 1);
            String(s.stack).endsWith(m) || (s.stack += `
` + o);
          }
        } catch {
        }
      }
      throw s;
    }
  }
  _request(t, n) {
    typeof t == "string" ? (n = n || {}, n.url = t) : n = t || {}, n = pe(this.defaults, n);
    const { transitional: s, paramsSerializer: r, headers: o } = n;
    s !== void 0 && Te.assertOptions(
      s,
      {
        silentJSONParsing: ee.transitional(ee.boolean),
        forcedJSONParsing: ee.transitional(ee.boolean),
        clarifyTimeoutError: ee.transitional(ee.boolean),
        legacyInterceptorReqResOrdering: ee.transitional(ee.boolean)
      },
      !1
    ), r != null && (d.isFunction(r) ? n.paramsSerializer = {
      serialize: r
    } : Te.assertOptions(
      r,
      {
        encode: ee.function,
        serialize: ee.function
      },
      !0
    )), n.allowAbsoluteUrls !== void 0 || (this.defaults.allowAbsoluteUrls !== void 0 ? n.allowAbsoluteUrls = this.defaults.allowAbsoluteUrls : n.allowAbsoluteUrls = !0), Te.assertOptions(
      n,
      {
        baseUrl: ee.spelling("baseURL"),
        withXsrfToken: ee.spelling("withXSRFToken")
      },
      !0
    ), n.method = (n.method || this.defaults.method || "get").toLowerCase();
    let a = o && d.merge(o.common, o[n.method]);
    o && d.forEach(["delete", "get", "head", "post", "put", "patch", "common"], (f) => {
      delete o[f];
    }), n.headers = X.concat(a, o);
    const c = [];
    let m = !0;
    this.interceptors.request.forEach(function(h) {
      if (typeof h.runWhen == "function" && h.runWhen(n) === !1)
        return;
      m = m && h.synchronous;
      const b = n.transitional || st;
      b && b.legacyInterceptorReqResOrdering ? c.unshift(h.fulfilled, h.rejected) : c.push(h.fulfilled, h.rejected);
    });
    const l = [];
    this.interceptors.response.forEach(function(h) {
      l.push(h.fulfilled, h.rejected);
    });
    let u, g = 0, v;
    if (!m) {
      const f = [kt.bind(this), void 0];
      for (f.unshift(...c), f.push(...l), v = f.length, u = Promise.resolve(n); g < v; )
        u = u.then(f[g++], f[g++]);
      return u;
    }
    v = c.length;
    let $ = n;
    for (; g < v; ) {
      const f = c[g++], h = c[g++];
      try {
        $ = f($);
      } catch (b) {
        h.call(this, b);
        break;
      }
    }
    try {
      u = kt.call(this, $);
    } catch (f) {
      return Promise.reject(f);
    }
    for (g = 0, v = l.length; g < v; )
      u = u.then(l[g++], l[g++]);
    return u;
  }
  getUri(t) {
    t = pe(this.defaults, t);
    const n = Vt(t.baseURL, t.url, t.allowAbsoluteUrls);
    return It(n, t.params, t.paramsSerializer);
  }
};
d.forEach(["delete", "get", "head", "options"], function(t) {
  fe.prototype[t] = function(n, s) {
    return this.request(
      pe(s || {}, {
        method: t,
        url: n,
        data: (s || {}).data
      })
    );
  };
});
d.forEach(["post", "put", "patch"], function(t) {
  function n(s) {
    return function(o, a, c) {
      return this.request(
        pe(c || {}, {
          method: t,
          headers: s ? {
            "Content-Type": "multipart/form-data"
          } : {},
          url: o,
          data: a
        })
      );
    };
  }
  fe.prototype[t] = n(), fe.prototype[t + "Form"] = n(!0);
});
let Is = class Xt {
  constructor(t) {
    if (typeof t != "function")
      throw new TypeError("executor must be a function.");
    let n;
    this.promise = new Promise(function(o) {
      n = o;
    });
    const s = this;
    this.promise.then((r) => {
      if (!s._listeners) return;
      let o = s._listeners.length;
      for (; o-- > 0; )
        s._listeners[o](r);
      s._listeners = null;
    }), this.promise.then = (r) => {
      let o;
      const a = new Promise((c) => {
        s.subscribe(c), o = c;
      }).then(r);
      return a.cancel = function() {
        s.unsubscribe(o);
      }, a;
    }, t(function(o, a, c) {
      s.reason || (s.reason = new Re(o, a, c), n(s.reason));
    });
  }
  /**
   * Throws a `CanceledError` if cancellation has been requested.
   */
  throwIfRequested() {
    if (this.reason)
      throw this.reason;
  }
  /**
   * Subscribe to the cancel signal
   */
  subscribe(t) {
    if (this.reason) {
      t(this.reason);
      return;
    }
    this._listeners ? this._listeners.push(t) : this._listeners = [t];
  }
  /**
   * Unsubscribe from the cancel signal
   */
  unsubscribe(t) {
    if (!this._listeners)
      return;
    const n = this._listeners.indexOf(t);
    n !== -1 && this._listeners.splice(n, 1);
  }
  toAbortSignal() {
    const t = new AbortController(), n = (s) => {
      t.abort(s);
    };
    return this.subscribe(n), t.signal.unsubscribe = () => this.unsubscribe(n), t.signal;
  }
  /**
   * Returns an object that contains a new `CancelToken` and a function that, when called,
   * cancels the `CancelToken`.
   */
  static source() {
    let t;
    return {
      token: new Xt(function(r) {
        t = r;
      }),
      cancel: t
    };
  }
};
function Ds(e) {
  return function(n) {
    return e.apply(null, n);
  };
}
function Bs(e) {
  return d.isObject(e) && e.isAxiosError === !0;
}
const Xe = {
  Continue: 100,
  SwitchingProtocols: 101,
  Processing: 102,
  EarlyHints: 103,
  Ok: 200,
  Created: 201,
  Accepted: 202,
  NonAuthoritativeInformation: 203,
  NoContent: 204,
  ResetContent: 205,
  PartialContent: 206,
  MultiStatus: 207,
  AlreadyReported: 208,
  ImUsed: 226,
  MultipleChoices: 300,
  MovedPermanently: 301,
  Found: 302,
  SeeOther: 303,
  NotModified: 304,
  UseProxy: 305,
  Unused: 306,
  TemporaryRedirect: 307,
  PermanentRedirect: 308,
  BadRequest: 400,
  Unauthorized: 401,
  PaymentRequired: 402,
  Forbidden: 403,
  NotFound: 404,
  MethodNotAllowed: 405,
  NotAcceptable: 406,
  ProxyAuthenticationRequired: 407,
  RequestTimeout: 408,
  Conflict: 409,
  Gone: 410,
  LengthRequired: 411,
  PreconditionFailed: 412,
  PayloadTooLarge: 413,
  UriTooLong: 414,
  UnsupportedMediaType: 415,
  RangeNotSatisfiable: 416,
  ExpectationFailed: 417,
  ImATeapot: 418,
  MisdirectedRequest: 421,
  UnprocessableEntity: 422,
  Locked: 423,
  FailedDependency: 424,
  TooEarly: 425,
  UpgradeRequired: 426,
  PreconditionRequired: 428,
  TooManyRequests: 429,
  RequestHeaderFieldsTooLarge: 431,
  UnavailableForLegalReasons: 451,
  InternalServerError: 500,
  NotImplemented: 501,
  BadGateway: 502,
  ServiceUnavailable: 503,
  GatewayTimeout: 504,
  HttpVersionNotSupported: 505,
  VariantAlsoNegotiates: 506,
  InsufficientStorage: 507,
  LoopDetected: 508,
  NotExtended: 510,
  NetworkAuthenticationRequired: 511,
  WebServerIsDown: 521,
  ConnectionTimedOut: 522,
  OriginIsUnreachable: 523,
  TimeoutOccurred: 524,
  SslHandshakeFailed: 525,
  InvalidSslCertificate: 526
};
Object.entries(Xe).forEach(([e, t]) => {
  Xe[t] = e;
});
function Qt(e) {
  const t = new fe(e), n = At(fe.prototype.request, t);
  return d.extend(n, fe.prototype, t, { allOwnKeys: !0 }), d.extend(n, t, null, { allOwnKeys: !0 }), n.create = function(r) {
    return Qt(pe(e, r));
  }, n;
}
const O = Qt(ze);
O.Axios = fe;
O.CanceledError = Re;
O.CancelToken = Is;
O.isCancel = qt;
O.VERSION = Gt;
O.toFormData = je;
O.AxiosError = z;
O.Cancel = O.CanceledError;
O.all = function(t) {
  return Promise.all(t);
};
O.spread = Ds;
O.isAxiosError = Bs;
O.mergeConfig = pe;
O.AxiosHeaders = X;
O.formToJSON = (e) => Dt(d.isHTMLForm(e) ? new FormData(e) : e);
O.getAdapter = Wt.getAdapter;
O.HttpStatusCode = Xe;
O.default = O;
const {
  Axios: Ha,
  AxiosError: Va,
  CanceledError: Ja,
  isCancel: Ka,
  CancelToken: Wa,
  VERSION: Ga,
  all: Xa,
  Cancel: Qa,
  isAxiosError: Ya,
  spread: Za,
  toFormData: ei,
  AxiosHeaders: ti,
  HttpStatusCode: ni,
  formToJSON: si,
  getAdapter: ri,
  mergeConfig: oi
} = O;
const qs = (e) => {
  for (const t in e)
    if (t.startsWith("aria-") || t === "role" || t === "title")
      return !0;
  return !1;
};
const zt = (e) => e === "";
const Hs = (...e) => e.filter((t, n, s) => !!t && t.trim() !== "" && s.indexOf(t) === n).join(" ").trim();
const Rt = (e) => e.replace(/([a-z0-9])([A-Z])/g, "$1-$2").toLowerCase();
const Vs = (e) => e.replace(
  /^([A-Z])|[\s-_]+(\w)/g,
  (t, n, s) => s ? s.toUpperCase() : n.toLowerCase()
);
const Js = (e) => {
  const t = Vs(e);
  return t.charAt(0).toUpperCase() + t.slice(1);
};
var ve = {
  xmlns: "http://www.w3.org/2000/svg",
  width: 24,
  height: 24,
  viewBox: "0 0 24 24",
  fill: "none",
  stroke: "currentColor",
  "stroke-width": 2,
  "stroke-linecap": "round",
  "stroke-linejoin": "round"
};
const Ks = ({
  name: e,
  iconNode: t,
  absoluteStrokeWidth: n,
  "absolute-stroke-width": s,
  strokeWidth: r,
  "stroke-width": o,
  size: a = ve.width,
  color: c = ve.stroke,
  ...m
}, { slots: l }) => Ve(
  "svg",
  {
    ...ve,
    ...m,
    width: a,
    height: a,
    stroke: c,
    "stroke-width": zt(n) || zt(s) || n === !0 || s === !0 ? Number(r || o || ve["stroke-width"]) * 24 / Number(a) : r || o || ve["stroke-width"],
    class: Hs(
      "lucide",
      m.class,
      ...e ? [`lucide-${Rt(Js(e))}-icon`, `lucide-${Rt(e)}`] : ["lucide-icon"]
    ),
    ...!l.default && !qs(m) && { "aria-hidden": "true" }
  },
  [...t.map((u) => Ve(...u)), ...l.default ? [l.default()] : []]
);
const I = (e, t) => (n, { slots: s, attrs: r }) => Ve(
  Ks,
  {
    ...r,
    ...n,
    iconNode: t,
    name: e
  },
  s
);
const Ws = I("archive", [
  ["rect", { width: "20", height: "5", x: "2", y: "3", rx: "1", key: "1wp1u1" }],
  ["path", { d: "M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8", key: "1s80jp" }],
  ["path", { d: "M10 12h4", key: "a56b0p" }]
]);
const Yt = I("book-open", [
  ["path", { d: "M12 7v14", key: "1akyts" }],
  [
    "path",
    {
      d: "M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z",
      key: "ruj8y"
    }
  ]
]);
const Qe = I("bot", [
  ["path", { d: "M12 8V4H8", key: "hb8ula" }],
  ["rect", { width: "16", height: "12", x: "4", y: "8", rx: "2", key: "enze0r" }],
  ["path", { d: "M2 14h2", key: "vft8re" }],
  ["path", { d: "M20 14h2", key: "4cs60a" }],
  ["path", { d: "M15 13v2", key: "1xurst" }],
  ["path", { d: "M9 13v2", key: "rq6x2g" }]
]);
const Gs = I("chevron-left", [
  ["path", { d: "m15 18-6-6 6-6", key: "1wnfg3" }]
]);
const Ye = I("circle-alert", [
  ["circle", { cx: "12", cy: "12", r: "10", key: "1mglay" }],
  ["line", { x1: "12", x2: "12", y1: "8", y2: "12", key: "1pkeuh" }],
  ["line", { x1: "12", x2: "12.01", y1: "16", y2: "16", key: "4dfq90" }]
]);
const Xs = I("circle-check", [
  ["circle", { cx: "12", cy: "12", r: "10", key: "1mglay" }],
  ["path", { d: "m9 12 2 2 4-4", key: "dzmm74" }]
]);
const Qs = I("circle-check-big", [
  ["path", { d: "M21.801 10A10 10 0 1 1 17 3.335", key: "yps3ct" }],
  ["path", { d: "m9 11 3 3L22 4", key: "1pflzl" }]
]);
const Ne = I("external-link", [
  ["path", { d: "M15 3h6v6", key: "1q9fwt" }],
  ["path", { d: "M10 14 21 3", key: "gplh6r" }],
  ["path", { d: "M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6", key: "a6xqqp" }]
]);
const Zt = I("image", [
  ["rect", { width: "18", height: "18", x: "3", y: "3", rx: "2", ry: "2", key: "1m3agn" }],
  ["circle", { cx: "9", cy: "9", r: "2", key: "af1f0g" }],
  ["path", { d: "m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21", key: "1xmnt7" }]
]);
const Ys = I("key", [
  ["path", { d: "m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4", key: "g0fldk" }],
  ["path", { d: "m21 2-9.6 9.6", key: "1j0ho8" }],
  ["circle", { cx: "7.5", cy: "15.5", r: "5.5", key: "yqb3hr" }]
]);
const ne = I("loader-circle", [
  ["path", { d: "M21 12a9 9 0 1 1-6.219-8.56", key: "13zald" }]
]);
const Pe = I("message-circle", [
  [
    "path",
    {
      d: "M2.992 16.342a2 2 0 0 1 .094 1.167l-1.065 3.29a1 1 0 0 0 1.236 1.168l3.413-.998a2 2 0 0 1 1.099.092 10 10 0 1 0-4.777-4.719",
      key: "1sd12s"
    }
  ]
]);
const en = I("mic", [
  ["path", { d: "M12 19v3", key: "npa21l" }],
  ["path", { d: "M19 10v2a7 7 0 0 1-14 0v-2", key: "1vc78b" }],
  ["rect", { x: "9", y: "2", width: "6", height: "13", rx: "3", key: "s6n7sd" }]
]);
const Ze = I("plus", [
  ["path", { d: "M5 12h14", key: "1ays0h" }],
  ["path", { d: "M12 5v14", key: "s699le" }]
]);
const Zs = I("refresh-cw", [
  ["path", { d: "M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8", key: "v9h5vc" }],
  ["path", { d: "M21 3v5h-5", key: "1q7to0" }],
  ["path", { d: "M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16", key: "3uifl3" }],
  ["path", { d: "M8 16H3v5", key: "1cv678" }]
]);
const er = I("send", [
  [
    "path",
    {
      d: "M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z",
      key: "1ffxy3"
    }
  ],
  ["path", { d: "m21.854 2.147-10.94 10.939", key: "12cjpa" }]
]);
const tr = I("settings-2", [
  ["path", { d: "M14 17H5", key: "gfn3mx" }],
  ["path", { d: "M19 7h-9", key: "6i9tg" }],
  ["circle", { cx: "17", cy: "17", r: "3", key: "18b49y" }],
  ["circle", { cx: "7", cy: "7", r: "3", key: "dfmy0x" }]
]);
const tn = I("sparkles", [
  [
    "path",
    {
      d: "M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z",
      key: "1s2grr"
    }
  ],
  ["path", { d: "M20 2v4", key: "1rf3ol" }],
  ["path", { d: "M22 4h-4", key: "gwowj6" }],
  ["circle", { cx: "4", cy: "20", r: "2", key: "6kqj1y" }]
]);
const nr = I("square", [
  ["rect", { width: "18", height: "18", x: "3", y: "3", rx: "2", key: "afitv7" }]
]);
const sr = I("trash-2", [
  ["path", { d: "M10 11v6", key: "nco0om" }],
  ["path", { d: "M14 11v6", key: "outv1u" }],
  ["path", { d: "M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6", key: "miytrc" }],
  ["path", { d: "M3 6h18", key: "d0wm0j" }],
  ["path", { d: "M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2", key: "e791ji" }]
]);
const et = I("x", [
  ["path", { d: "M18 6 6 18", key: "1bl5f8" }],
  ["path", { d: "m6 6 12 12", key: "d8bk6v" }]
]), rr = {
  key: 0,
  class: "fixed inset-0 z-[200] flex justify-end"
}, or = { class: "relative flex h-full w-full max-w-md flex-col bg-white shadow-xl dark:bg-zinc-900" }, ar = { class: "flex items-center justify-between border-b border-zinc-200 px-5 py-4 dark:border-zinc-700" }, ir = { class: "flex-1 overflow-y-auto p-5 space-y-5" }, lr = { class: "text-sm text-zinc-600 dark:text-zinc-400" }, cr = {
  href: "https://openrouter.ai/keys",
  target: "_blank",
  rel: "noopener",
  class: "inline-flex items-center gap-1 text-indigo-600 hover:underline"
}, dr = {
  key: 0,
  class: "flex justify-center py-8"
}, ur = ["placeholder"], fr = { class: "flex items-center gap-3 cursor-pointer" }, pr = {
  key: 0,
  class: "text-xs text-zinc-500"
}, mr = {
  key: 1,
  class: "rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300"
}, hr = {
  key: 2,
  class: "rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300"
}, gr = {
  key: 3,
  class: "rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"
}, br = { class: "flex gap-2 border-t border-zinc-200 p-5 dark:border-zinc-700" }, xr = ["disabled"], vr = { key: 1 }, yr = ["disabled"], wr = { key: 1 }, kr = {
  __name: "IntegrationsSidebar",
  props: {
    open: { type: Boolean, default: !1 }
  },
  emits: ["close", "saved"],
  setup(e, { emit: t }) {
    const n = e, s = t, r = S({ api_key: "", is_active: !0 }), o = S({
      configured: !1,
      is_active: !1,
      has_token: !1,
      last_tested_at: null,
      last_error: null
    }), a = S(!1), c = S(!1), m = S(!1), l = S(null), u = S(null);
    Et(
      () => n.open,
      (f) => {
        f && g();
      },
      { immediate: !0 }
    );
    async function g() {
      a.value = !0, l.value = null;
      try {
        const { data: f } = await O.get("/ai-member/connection");
        o.value = f.connection ?? o.value, r.value.is_active = o.value.is_active ?? !0, r.value.api_key = "";
      } catch (f) {
        l.value = f?.response?.data?.message || "Não foi possível carregar a conexão.";
      } finally {
        a.value = !1;
      }
    }
    async function v() {
      if (l.value = null, u.value = null, !r.value.api_key?.trim() && !o.value.configured) {
        l.value = "Informe a API key do OpenRouter.";
        return;
      }
      c.value = !0;
      try {
        const { data: f } = await O.put("/ai-member/connection", {
          api_key: r.value.api_key?.trim() || void 0,
          is_active: r.value.is_active
        });
        o.value = f.connection ?? o.value, r.value.api_key = "", u.value = "Conexão salva com sucesso.", s("saved");
      } catch (f) {
        l.value = f?.response?.data?.message || "Falha ao salvar.";
      } finally {
        c.value = !1;
      }
    }
    async function $() {
      l.value = null, u.value = null, m.value = !0;
      try {
        const { data: f } = await O.post("/ai-member/connection/test");
        f?.success ? (u.value = f.message || "Conexão OK.", await g(), s("saved")) : l.value = f?.message || "Falha no teste.";
      } catch (f) {
        l.value = f?.response?.data?.message || "Falha no teste.";
      } finally {
        m.value = !1;
      }
    }
    return (f, h) => (x(), H(Ct, { to: "body" }, [
      e.open ? (x(), y("div", rr, [
        i("div", {
          class: "absolute inset-0 bg-black/40",
          onClick: h[0] || (h[0] = (b) => s("close"))
        }),
        i("aside", or, [
          i("div", ar, [
            h[4] || (h[4] = i("div", null, [
              i("h2", { class: "text-lg font-semibold text-zinc-900 dark:text-white" }, "OpenRouter"),
              i("p", { class: "text-sm text-zinc-500" }, "Credenciais para o AI Member")
            ], -1)),
            i("button", {
              type: "button",
              class: "rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800",
              onClick: h[1] || (h[1] = (b) => s("close"))
            }, [
              T(w(et), { class: "h-5 w-5" })
            ])
          ]),
          i("div", ir, [
            i("p", lr, [
              h[6] || (h[6] = B(" Obtenha sua API key em ", -1)),
              i("a", cr, [
                h[5] || (h[5] = B(" openrouter.ai/keys ", -1)),
                T(w(Ne), { class: "h-3.5 w-3.5" })
              ])
            ]),
            a.value ? (x(), y("div", dr, [
              T(w(ne), { class: "h-6 w-6 animate-spin text-zinc-400" })
            ])) : (x(), y(he, { key: 1 }, [
              i("div", null, [
                h[7] || (h[7] = i("label", { class: "mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300" }, "API Key", -1)),
                D(i("input", {
                  "onUpdate:modelValue": h[2] || (h[2] = (b) => r.value.api_key = b),
                  type: "password",
                  class: "w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800",
                  placeholder: o.value.has_token ? "•••••••• (deixe em branco para manter)" : "sk-or-..."
                }, null, 8, ur), [
                  [Y, r.value.api_key]
                ])
              ]),
              i("label", fr, [
                D(i("input", {
                  "onUpdate:modelValue": h[3] || (h[3] = (b) => r.value.is_active = b),
                  type: "checkbox",
                  class: "rounded border-zinc-300"
                }, null, 512), [
                  [ye, r.value.is_active]
                ]),
                h[8] || (h[8] = i("span", { class: "text-sm text-zinc-700 dark:text-zinc-300" }, "Integração ativa", -1))
              ]),
              o.value.last_tested_at ? (x(), y("div", pr, " Último teste: " + N(new Date(o.value.last_tested_at).toLocaleString("pt-BR")), 1)) : j("", !0),
              o.value.last_error ? (x(), y("div", mr, N(o.value.last_error), 1)) : j("", !0),
              l.value ? (x(), y("div", hr, N(l.value), 1)) : j("", !0),
              u.value ? (x(), y("div", gr, N(u.value), 1)) : j("", !0)
            ], 64))
          ]),
          i("div", br, [
            i("button", {
              type: "button",
              class: "flex-1 rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium dark:border-zinc-600",
              disabled: m.value || !o.value.configured,
              onClick: $
            }, [
              m.value ? (x(), H(w(ne), {
                key: 0,
                class: "mx-auto h-4 w-4 animate-spin"
              })) : (x(), y("span", vr, "Testar"))
            ], 8, xr),
            i("button", {
              type: "button",
              class: "flex-1 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50",
              disabled: c.value,
              onClick: v
            }, [
              c.value ? (x(), H(w(ne), {
                key: 0,
                class: "mx-auto h-4 w-4 animate-spin"
              })) : (x(), y("span", wr, "Salvar"))
            ], 8, yr)
          ])
        ])
      ])) : j("", !0)
    ]));
  }
}, _r = { class: "mx-auto max-w-4xl space-y-6 pb-8" }, zr = { class: "relative overflow-hidden rounded-2xl border border-zinc-200/80 bg-gradient-to-br from-indigo-50 via-white to-violet-50 p-5 shadow-sm dark:border-zinc-800 dark:from-indigo-950/40 dark:via-zinc-900 dark:to-violet-950/30 sm:p-6" }, Rr = { class: "relative flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between" }, Er = { class: "flex items-start gap-4" }, Cr = { class: "flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-indigo-600 text-white shadow-lg shadow-indigo-600/25" }, Sr = { class: "flex flex-wrap items-center gap-2" }, Ar = { class: "mt-1 max-w-xl text-sm leading-relaxed text-zinc-600 dark:text-zinc-400" }, Or = { class: "font-medium text-zinc-800 dark:text-zinc-200" }, Tr = {
  key: 0,
  class: "mt-3 inline-flex flex-wrap items-center gap-1 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
}, $r = {
  href: "/ai-member",
  class: "inline-flex items-center gap-0.5 font-medium text-indigo-600 hover:underline dark:text-indigo-400"
}, Nr = { class: "hidden shrink-0 rounded-2xl border border-zinc-200/80 bg-zinc-950 p-4 shadow-inner dark:border-zinc-700 sm:block" }, Pr = { class: "relative h-24 w-36 overflow-hidden rounded-xl bg-zinc-900" }, Ur = ["src"], Fr = { class: "mt-2 truncate text-center text-xs font-medium text-zinc-300" }, Lr = {
  key: 0,
  class: "flex justify-center py-20"
}, jr = { class: "flex items-center justify-between gap-4 rounded-2xl border border-zinc-200/80 bg-white px-4 py-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60 sm:px-5" }, Mr = { class: "relative inline-flex shrink-0 cursor-pointer items-center" }, Ir = ["disabled"], Dr = { class: "grid gap-6 lg:grid-cols-2" }, Br = { class: "space-y-4 rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60" }, qr = { class: "flex items-center gap-2" }, Hr = { class: "space-y-4" }, Vr = { class: "grid gap-4 sm:grid-cols-2" }, Jr = { class: "space-y-4" }, Kr = { class: "mt-1 text-xs text-zinc-500" }, Wr = { key: 0 }, Gr = { key: 1 }, Xr = { class: "flex flex-wrap items-center gap-3" }, Qr = ["src"], Yr = { class: "flex flex-wrap gap-2" }, Zr = ["disabled"], eo = { key: 1 }, to = ["disabled"], no = { class: "space-y-4 rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60" }, so = { class: "flex items-center gap-2" }, ro = { class: "space-y-3" }, oo = { class: "flex cursor-pointer items-center gap-3 rounded-xl border border-zinc-200/80 px-3 py-3 transition hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800/50" }, ao = { class: "flex cursor-pointer items-center gap-3 rounded-xl border border-zinc-200/80 px-3 py-3 transition hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800/50" }, io = { class: "grid grid-cols-2 gap-3 pt-1" }, lo = { class: "rounded-xl bg-zinc-50 px-3 py-2.5 dark:bg-zinc-800/50" }, co = { class: "mt-0.5 text-lg font-semibold tabular-nums text-zinc-900 dark:text-white" }, uo = { class: "rounded-xl bg-zinc-50 px-3 py-2.5 dark:bg-zinc-800/50" }, fo = { class: "mt-0.5 text-lg font-semibold tabular-nums text-zinc-900 dark:text-white" }, po = { class: "space-y-4 rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60" }, mo = { class: "flex items-center gap-2" }, ho = { class: "grid gap-4 lg:grid-cols-2" }, go = { class: "grid gap-4 lg:grid-cols-2" }, bo = { class: "space-y-4 rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60" }, xo = { class: "flex flex-wrap items-start justify-between gap-3" }, vo = { class: "flex items-center gap-2" }, yo = ["disabled"], wo = { class: "flex flex-wrap gap-2" }, ko = { class: "inline-flex items-center rounded-full bg-indigo-50 px-3 py-1 text-xs font-medium text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300" }, _o = {
  key: 0,
  class: "inline-flex items-center rounded-full bg-zinc-100 px-3 py-1 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400"
}, zo = {
  key: 1,
  class: "inline-flex items-center rounded-full bg-zinc-100 px-3 py-1 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400"
}, Ro = {
  key: 0,
  class: "space-y-2"
}, Eo = { class: "min-w-0" }, Co = { class: "truncate text-sm font-medium text-zinc-900 dark:text-white" }, So = { class: "mt-0.5 line-clamp-2 text-xs leading-relaxed text-zinc-500" }, Ao = ["onClick"], Oo = {
  key: 1,
  class: "text-sm text-zinc-500 dark:text-zinc-400"
}, To = { class: "rounded-xl border border-dashed border-zinc-300 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-900/30" }, $o = { class: "space-y-3" }, No = { class: "flex justify-end border-t border-zinc-200/80 pt-4 dark:border-zinc-800" }, Po = ["disabled"], ae = "w-full rounded-xl border border-zinc-200/80 bg-white px-3.5 py-2.5 text-sm text-zinc-900 shadow-sm transition placeholder:text-zinc-400 focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-zinc-700 dark:bg-zinc-900/80 dark:text-zinc-100 dark:placeholder:text-zinc-500", te = "mb-1.5 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400", Uo = {
  __name: "ProductAgentPanel",
  props: {
    produto: { type: Object, required: !0 }
  },
  setup(e) {
    const t = e, n = S(!0), s = S(!1), r = S(!1), o = S(""), a = S(""), c = S(!1), m = S([]), l = S({
      enabled: !1,
      name: "Assistente",
      gender: "neutral",
      personality: "",
      temperature: 0.7,
      max_tokens: 800,
      system_instructions: "",
      welcome_message: "Olá! Sou seu assistente. Como posso ajudar?",
      intro_headline: "Como posso ajudar você hoje?",
      widget_icon: "",
      widget_color: "#6366f1",
      widget_color_source: "theme",
      allow_image: !0,
      allow_audio: !0,
      knowledge_indexed_at: null,
      knowledge_chunks_count: 0
    }), u = S({ title: "", content: "" }), g = Z(() => t.produto?.member_area_config?.theme?.primary || "#0ea5e9"), v = Z(
      () => l.value.widget_color_source === "custom" ? l.value.widget_color || "#6366f1" : g.value
    ), $ = S(!1), f = S(null), h = Z(() => t.produto?.id), b = Z(() => l.value.knowledge_indexed_at ? new Date(l.value.knowledge_indexed_at).toLocaleString("pt-BR") : null), C = Z(() => c.value ? l.value.enabled ? "Agente ativo" : "Pronto para ativar" : "OpenRouter pendente"), L = Z(() => c.value ? l.value.enabled ? "emerald" : "indigo" : "amber");
    async function U() {
      n.value = !0, o.value = "";
      try {
        const { data: A } = await O.get(`/ai-member/agents/${h.value}`);
        l.value = { ...l.value, ...A.agent }, m.value = A.documents ?? [], c.value = !!A.openrouter_configured;
      } catch (A) {
        o.value = A?.response?.data?.message || "Erro ao carregar configurações.";
      } finally {
        n.value = !1;
      }
    }
    async function q() {
      s.value = !0, o.value = "", a.value = "";
      try {
        const { data: A } = await O.put(`/ai-member/agents/${h.value}`, l.value);
        l.value = { ...l.value, ...A.agent }, a.value = "Configurações salvas com sucesso.";
      } catch (A) {
        o.value = A?.response?.data?.message || "Erro ao salvar.";
      } finally {
        s.value = !1;
      }
    }
    async function W() {
      r.value = !0, o.value = "", a.value = "";
      try {
        const { data: A } = await O.post(`/ai-member/agents/${h.value}/reindex`);
        A.agent && (l.value = { ...l.value, ...A.agent }), a.value = A.message || "Indexação concluída.";
      } catch (A) {
        o.value = A?.response?.data?.message || "Erro na indexação.";
      } finally {
        r.value = !1;
      }
    }
    async function se() {
      if (!(!u.value.title.trim() || !u.value.content.trim()))
        try {
          const { data: A } = await O.post(`/ai-member/agents/${h.value}/documents`, u.value);
          m.value.push(A.document), u.value = { title: "", content: "" }, a.value = "Documento adicionado e indexação agendada.";
        } catch (A) {
          o.value = A?.response?.data?.message || "Erro ao adicionar documento.";
        }
    }
    async function M(A) {
      try {
        await O.delete(`/ai-member/agents/${h.value}/documents/${A}`), m.value = m.value.filter((p) => p.id !== A);
      } catch (p) {
        o.value = p?.response?.data?.message || "Erro ao remover.";
      }
    }
    async function ie(A) {
      const p = A.target.files?.[0];
      if (p) {
        if (p.size > 2 * 1024 * 1024) {
          o.value = "O ícone deve ter no máximo 2 MB.", A.target.value = "";
          return;
        }
        $.value = !0, o.value = "", a.value = "";
        try {
          const k = new FormData();
          k.append("file", p);
          const { data: V } = await O.post(`/ai-member/agents/${h.value}/widget-icon`, k, {
            headers: { "Content-Type": "multipart/form-data" }
          });
          V.agent ? l.value = { ...l.value, ...V.agent } : V.url && (l.value.widget_icon = V.url), a.value = "Ícone atualizado.";
        } catch (k) {
          o.value = k?.response?.data?.message || "Erro ao enviar ícone.";
        } finally {
          $.value = !1, A.target.value = "";
        }
      }
    }
    async function ce() {
      $.value = !0, o.value = "";
      try {
        const { data: A } = await O.delete(`/ai-member/agents/${h.value}/widget-icon`);
        A.agent ? l.value = { ...l.value, ...A.agent } : l.value.widget_icon = "", a.value = "Ícone removido.";
      } catch (A) {
        o.value = A?.response?.data?.message || "Erro ao remover ícone.";
      } finally {
        $.value = !1;
      }
    }
    return St(U), (A, p) => (x(), y("div", _r, [
      i("div", zr, [
        p[25] || (p[25] = i("div", { class: "pointer-events-none absolute -right-8 -top-8 h-32 w-32 rounded-full bg-indigo-400/10 blur-2xl dark:bg-indigo-500/20" }, null, -1)),
        i("div", Rr, [
          i("div", Er, [
            i("div", Cr, [
              T(w(Qe), { class: "h-6 w-6" })
            ]),
            i("div", null, [
              i("div", Sr, [
                p[16] || (p[16] = i("h2", { class: "text-lg font-semibold tracking-tight text-zinc-900 dark:text-white" }, " Agente de suporte IA ", -1)),
                i("span", {
                  class: P(["inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium", {
                    "bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300": L.value === "amber",
                    "bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300": L.value === "emerald",
                    "bg-indigo-100 text-indigo-800 dark:bg-indigo-950/60 dark:text-indigo-300": L.value === "indigo"
                  }])
                }, [
                  L.value === "amber" ? (x(), H(w(Ye), {
                    key: 0,
                    class: "h-3 w-3"
                  })) : (x(), H(w(Xs), {
                    key: 1,
                    class: "h-3 w-3"
                  })),
                  B(" " + N(C.value), 1)
                ], 2)
              ]),
              i("p", Ar, [
                p[17] || (p[17] = B(" Personalize o assistente flutuante que seus alunos verão na área de membros de ", -1)),
                i("strong", Or, N(e.produto.name), 1),
                p[18] || (p[18] = B(". ", -1))
              ]),
              c.value ? j("", !0) : (x(), y("p", Tr, [
                T(w(Ye), { class: "h-4 w-4 shrink-0" }),
                p[20] || (p[20] = B(" Configure o OpenRouter em ", -1)),
                i("a", $r, [
                  p[19] || (p[19] = B(" AI Member ", -1)),
                  T(w(Ne), { class: "h-3.5 w-3.5" })
                ]),
                p[21] || (p[21] = B(" antes de ativar. ", -1))
              ]))
            ])
          ]),
          i("div", Nr, [
            p[24] || (p[24] = i("p", { class: "mb-3 text-[10px] font-medium uppercase tracking-wider text-zinc-500" }, "Preview", -1)),
            i("div", Pr, [
              p[22] || (p[22] = i("div", { class: "absolute inset-x-3 top-3 h-2 rounded-full bg-zinc-800" }, null, -1)),
              p[23] || (p[23] = i("div", { class: "absolute inset-x-3 top-7 h-16 rounded-lg bg-zinc-800/80" }, null, -1)),
              i("div", {
                class: "absolute bottom-2 right-2 flex h-9 w-9 items-center justify-center rounded-full shadow-lg",
                style: de({ backgroundColor: v.value })
              }, [
                l.value.widget_icon ? (x(), y("img", {
                  key: 0,
                  src: l.value.widget_icon,
                  alt: "",
                  class: "h-5 w-5 rounded-full object-cover"
                }, null, 8, Ur)) : (x(), H(w(Pe), {
                  key: 1,
                  class: "h-4 w-4 text-white"
                }))
              ], 4)
            ]),
            i("p", Fr, N(l.value.name || "Assistente"), 1)
          ])
        ])
      ]),
      n.value ? (x(), y("div", Lr, [
        T(w(ne), { class: "h-8 w-8 animate-spin text-indigo-500" })
      ])) : (x(), y(he, { key: 1 }, [
        i("div", jr, [
          p[28] || (p[28] = i("div", null, [
            i("p", { class: "font-medium text-zinc-900 dark:text-white" }, "Ativar agente nesta área"),
            i("p", { class: "mt-0.5 text-sm text-zinc-500 dark:text-zinc-400" }, " Exibe o chat flutuante para alunos com acesso ao produto. ")
          ], -1)),
          i("label", Mr, [
            D(i("input", {
              "onUpdate:modelValue": p[0] || (p[0] = (k) => l.value.enabled = k),
              type: "checkbox",
              class: "peer sr-only",
              disabled: !c.value
            }, null, 8, Ir), [
              [ye, l.value.enabled]
            ]),
            p[26] || (p[26] = i("span", { class: "h-7 w-12 rounded-full bg-zinc-200 transition peer-checked:bg-indigo-600 peer-disabled:opacity-40 dark:bg-zinc-700" }, null, -1)),
            p[27] || (p[27] = i("span", { class: "absolute left-0.5 top-0.5 h-6 w-6 rounded-full bg-white shadow transition peer-checked:translate-x-5" }, null, -1))
          ])
        ]),
        i("div", Dr, [
          i("section", Br, [
            i("div", qr, [
              T(w(tn), { class: "h-4 w-4 text-indigo-500" }),
              p[29] || (p[29] = i("h3", { class: "text-sm font-semibold text-zinc-900 dark:text-white" }, "Identidade", -1))
            ]),
            i("div", Hr, [
              i("div", null, [
                i("label", {
                  class: P(te)
                }, "Nome do agente"),
                D(i("input", {
                  "onUpdate:modelValue": p[1] || (p[1] = (k) => l.value.name = k),
                  type: "text",
                  class: P(ae),
                  placeholder: "Ex.: Ana, Suporte..."
                }, null, 512), [
                  [Y, l.value.name]
                ])
              ]),
              i("div", null, [
                i("label", {
                  class: P(te)
                }, "Gênero / voz"),
                D(i("select", {
                  "onUpdate:modelValue": p[2] || (p[2] = (k) => l.value.gender = k),
                  class: P(ae)
                }, [...p[30] || (p[30] = [
                  i("option", { value: "neutral" }, "Neutro", -1),
                  i("option", { value: "male" }, "Masculino", -1),
                  i("option", { value: "female" }, "Feminino", -1)
                ])], 512), [
                  [at, l.value.gender]
                ])
              ]),
              i("div", Vr, [
                i("div", Jr, [
                  i("div", null, [
                    i("label", {
                      class: P(te)
                    }, "Cor do widget"),
                    D(i("select", {
                      "onUpdate:modelValue": p[3] || (p[3] = (k) => l.value.widget_color_source = k),
                      class: P(ae)
                    }, [...p[31] || (p[31] = [
                      i("option", { value: "theme" }, "Usar cor primária da área de membros", -1),
                      i("option", { value: "custom" }, "Cor personalizada", -1)
                    ])], 512), [
                      [at, l.value.widget_color_source]
                    ]),
                    i("p", Kr, " Tema atual: " + N(g.value), 1)
                  ]),
                  l.value.widget_color_source === "custom" ? (x(), y("div", Wr, [
                    i("label", {
                      class: P(te)
                    }, "Cor personalizada"),
                    D(i("input", {
                      "onUpdate:modelValue": p[4] || (p[4] = (k) => l.value.widget_color = k),
                      type: "color",
                      class: "h-11 w-14 cursor-pointer rounded-xl border border-zinc-200 dark:border-zinc-700"
                    }, null, 512), [
                      [Y, l.value.widget_color]
                    ])
                  ])) : (x(), y("div", Gr, [
                    i("label", {
                      class: P(te)
                    }, "Cor do tema"),
                    i("div", {
                      class: "h-11 w-14 rounded-xl border border-zinc-200 dark:border-zinc-700",
                      style: de({ backgroundColor: g.value })
                    }, null, 4)
                  ]))
                ]),
                i("div", null, [
                  i("label", {
                    class: P(te)
                  }, "Ícone do widget"),
                  i("input", {
                    ref_key: "iconFileInput",
                    ref: f,
                    type: "file",
                    accept: "image/png,image/jpeg,image/webp,image/gif",
                    class: "hidden",
                    onChange: ie
                  }, null, 544),
                  i("div", Xr, [
                    i("div", {
                      class: "flex h-14 w-14 items-center justify-center overflow-hidden rounded-2xl border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/60",
                      style: de(l.value.widget_icon ? {} : { backgroundColor: v.value })
                    }, [
                      l.value.widget_icon ? (x(), y("img", {
                        key: 0,
                        src: l.value.widget_icon,
                        alt: "",
                        class: "h-full w-full object-cover"
                      }, null, 8, Qr)) : (x(), H(w(Qe), {
                        key: 1,
                        class: "h-6 w-6 text-white"
                      }))
                    ], 4),
                    i("div", Yr, [
                      i("button", {
                        type: "button",
                        class: "rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm font-medium text-zinc-700 shadow-sm transition hover:bg-zinc-50 disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200",
                        disabled: $.value,
                        onClick: p[5] || (p[5] = (k) => f.value?.click())
                      }, [
                        $.value ? (x(), H(w(ne), {
                          key: 0,
                          class: "inline h-4 w-4 animate-spin"
                        })) : (x(), y("span", eo, N(l.value.widget_icon ? "Trocar ícone" : "Enviar ícone"), 1))
                      ], 8, Zr),
                      l.value.widget_icon ? (x(), y("button", {
                        key: 0,
                        type: "button",
                        class: "rounded-xl px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 disabled:opacity-50 dark:hover:bg-red-950/30",
                        disabled: $.value,
                        onClick: ce
                      }, " Remover ", 8, to)) : j("", !0)
                    ])
                  ]),
                  p[32] || (p[32] = i("p", { class: "mt-1.5 text-xs text-zinc-500" }, "PNG, JPG ou WebP · máx. 2 MB · ideal 256×256 px", -1))
                ])
              ])
            ])
          ]),
          i("section", no, [
            i("div", so, [
              T(w(tr), { class: "h-4 w-4 text-indigo-500" }),
              p[33] || (p[33] = i("h3", { class: "text-sm font-semibold text-zinc-900 dark:text-white" }, "Capacidades", -1))
            ]),
            i("div", ro, [
              i("label", oo, [
                D(i("input", {
                  "onUpdate:modelValue": p[6] || (p[6] = (k) => l.value.allow_image = k),
                  type: "checkbox",
                  class: "rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500/30"
                }, null, 512), [
                  [ye, l.value.allow_image]
                ]),
                T(w(Zt), { class: "h-4 w-4 text-zinc-400" }),
                p[34] || (p[34] = i("div", null, [
                  i("p", { class: "text-sm font-medium text-zinc-900 dark:text-white" }, "Enviar imagens"),
                  i("p", { class: "text-xs text-zinc-500" }, "Aluno pode mandar prints e fotos no chat.")
                ], -1))
              ]),
              i("label", ao, [
                D(i("input", {
                  "onUpdate:modelValue": p[7] || (p[7] = (k) => l.value.allow_audio = k),
                  type: "checkbox",
                  class: "rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500/30"
                }, null, 512), [
                  [ye, l.value.allow_audio]
                ]),
                T(w(en), { class: "h-4 w-4 text-zinc-400" }),
                p[35] || (p[35] = i("div", null, [
                  i("p", { class: "text-sm font-medium text-zinc-900 dark:text-white" }, "Enviar áudio"),
                  i("p", { class: "text-xs text-zinc-500" }, "Mensagens de voz gravadas no navegador.")
                ], -1))
              ])
            ]),
            i("div", io, [
              i("div", lo, [
                p[36] || (p[36] = i("p", { class: "text-[10px] font-medium uppercase tracking-wide text-zinc-500" }, "Temperatura", -1)),
                i("p", co, N(l.value.temperature), 1)
              ]),
              i("div", uo, [
                p[37] || (p[37] = i("p", { class: "text-[10px] font-medium uppercase tracking-wide text-zinc-500" }, "Máx. tokens", -1)),
                i("p", fo, N(l.value.max_tokens), 1)
              ])
            ])
          ])
        ]),
        i("section", po, [
          i("div", mo, [
            T(w(Pe), { class: "h-4 w-4 text-indigo-500" }),
            p[38] || (p[38] = i("h3", { class: "text-sm font-semibold text-zinc-900 dark:text-white" }, "Comportamento", -1))
          ]),
          i("div", ho, [
            i("div", null, [
              i("label", {
                class: P(te)
              }, "Temperatura · " + N(l.value.temperature), 1),
              D(i("input", {
                "onUpdate:modelValue": p[8] || (p[8] = (k) => l.value.temperature = k),
                type: "range",
                min: "0",
                max: "2",
                step: "0.1",
                class: "h-2 w-full cursor-pointer appearance-none rounded-full bg-zinc-200 accent-indigo-600 dark:bg-zinc-700"
              }, null, 512), [
                [
                  Y,
                  l.value.temperature,
                  void 0,
                  { number: !0 }
                ]
              ]),
              p[39] || (p[39] = i("p", { class: "mt-1 text-xs text-zinc-500" }, "Mais baixo = respostas objetivas. Mais alto = mais criativo.", -1))
            ]),
            i("div", null, [
              i("label", {
                class: P(te)
              }, "Máx. tokens por resposta"),
              D(i("input", {
                "onUpdate:modelValue": p[9] || (p[9] = (k) => l.value.max_tokens = k),
                type: "number",
                min: "100",
                max: "4000",
                class: P(ae)
              }, null, 512), [
                [
                  Y,
                  l.value.max_tokens,
                  void 0,
                  { number: !0 }
                ]
              ])
            ])
          ]),
          i("div", go, [
            i("div", null, [
              i("label", {
                class: P(te)
              }, "Personalidade / tom"),
              D(i("textarea", {
                "onUpdate:modelValue": p[10] || (p[10] = (k) => l.value.personality = k),
                rows: "3",
                class: P(ae),
                placeholder: "Amigável, paciente, didático..."
              }, null, 512), [
                [Y, l.value.personality]
              ])
            ]),
            i("div", null, [
              i("label", {
                class: P(te)
              }, "Título de boas-vindas"),
              D(i("input", {
                "onUpdate:modelValue": p[11] || (p[11] = (k) => l.value.intro_headline = k),
                type: "text",
                class: P(ae),
                placeholder: "Ex.: Como posso ajudar você hoje?"
              }, null, 512), [
                [Y, l.value.intro_headline]
              ])
            ]),
            i("div", null, [
              i("label", {
                class: P(te)
              }, "Mensagem de apresentação"),
              D(i("textarea", {
                "onUpdate:modelValue": p[12] || (p[12] = (k) => l.value.welcome_message = k),
                rows: "3",
                class: P(ae),
                placeholder: "Texto exibido na tela inicial do chat..."
              }, null, 512), [
                [Y, l.value.welcome_message]
              ])
            ])
          ]),
          i("div", null, [
            i("label", {
              class: P(te)
            }, "Instruções customizadas"),
            D(i("textarea", {
              "onUpdate:modelValue": p[13] || (p[13] = (k) => l.value.system_instructions = k),
              rows: "4",
              class: P(ae),
              placeholder: "Regras de suporte, políticas do produto, o que o agente pode ou não fazer..."
            }, null, 512), [
              [Y, l.value.system_instructions]
            ])
          ])
        ]),
        i("section", bo, [
          i("div", xo, [
            i("div", vo, [
              T(w(Yt), { class: "h-4 w-4 text-indigo-500" }),
              p[40] || (p[40] = i("h3", { class: "text-sm font-semibold text-zinc-900 dark:text-white" }, "Base de conhecimento", -1))
            ]),
            i("button", {
              type: "button",
              class: "inline-flex items-center gap-2 rounded-xl border border-zinc-200 bg-white px-3.5 py-2 text-sm font-medium text-zinc-700 shadow-sm transition hover:bg-zinc-50 disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-750",
              disabled: r.value,
              onClick: W
            }, [
              T(w(Zs), {
                class: P(["h-4 w-4", { "animate-spin": r.value }])
              }, null, 8, ["class"]),
              p[41] || (p[41] = B(" Sincronizar aulas ", -1))
            ], 8, yo)
          ]),
          i("div", wo, [
            i("span", ko, N(l.value.knowledge_chunks_count) + " trechos indexados ", 1),
            b.value ? (x(), y("span", _o, " Atualizado " + N(b.value), 1)) : j("", !0),
            m.value.length ? (x(), y("span", zo, N(m.value.length) + " FAQ" + N(m.value.length === 1 ? "" : "s") + " extra ", 1)) : j("", !0)
          ]),
          m.value.length ? (x(), y("div", Ro, [
            (x(!0), y(he, null, Je(m.value, (k) => (x(), y("div", {
              key: k.id,
              class: "group flex items-start justify-between gap-3 rounded-xl border border-zinc-200/80 bg-zinc-50/80 px-3 py-3 dark:border-zinc-700 dark:bg-zinc-800/40"
            }, [
              i("div", Eo, [
                i("p", Co, N(k.title), 1),
                i("p", So, N(k.content), 1)
              ]),
              i("button", {
                type: "button",
                class: "shrink-0 rounded-lg p-1.5 text-zinc-400 opacity-0 transition hover:bg-red-50 hover:text-red-600 group-hover:opacity-100 dark:hover:bg-red-950/30",
                title: "Remover",
                onClick: (V) => M(k.id)
              }, [
                T(w(sr), { class: "h-4 w-4" })
              ], 8, Ao)
            ]))), 128))
          ])) : (x(), y("p", Oo, " Nenhum FAQ extra. As aulas do produto são indexadas automaticamente ao sincronizar. ")),
          i("div", To, [
            p[43] || (p[43] = i("p", { class: "mb-3 text-xs font-medium uppercase tracking-wide text-zinc-500" }, "Adicionar FAQ", -1)),
            i("div", $o, [
              D(i("input", {
                "onUpdate:modelValue": p[14] || (p[14] = (k) => u.value.title = k),
                type: "text",
                class: P(ae),
                placeholder: "Título (ex.: Como acessar as aulas?)"
              }, null, 512), [
                [Y, u.value.title]
              ]),
              D(i("textarea", {
                "onUpdate:modelValue": p[15] || (p[15] = (k) => u.value.content = k),
                rows: "3",
                class: P(ae),
                placeholder: "Resposta ou conteúdo de treinamento..."
              }, null, 512), [
                [Y, u.value.content]
              ]),
              i("button", {
                type: "button",
                class: "inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 hover:text-indigo-700 dark:text-indigo-400",
                onClick: se
              }, [
                T(w(Ze), { class: "h-4 w-4" }),
                p[42] || (p[42] = B(" Adicionar documento ", -1))
              ])
            ])
          ])
        ]),
        o.value || a.value ? (x(), y("div", {
          key: 0,
          class: P(["rounded-xl px-4 py-3 text-sm", o.value ? "border border-red-200 bg-red-50 text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-200" : "border border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-200"])
        }, N(o.value || a.value), 3)) : j("", !0),
        i("div", No, [
          i("button", {
            type: "button",
            class: "inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-indigo-600/20 transition hover:bg-indigo-700 disabled:opacity-50",
            disabled: s.value,
            onClick: q
          }, [
            s.value ? (x(), H(w(ne), {
              key: 0,
              class: "h-4 w-4 animate-spin"
            })) : j("", !0),
            i("span", null, N(s.value ? "Salvando…" : "Salvar agente"), 1)
          ], 8, Po)
        ])
      ], 64))
    ]));
  }
}, Fo = ["aria-label"], Lo = ["src"], jo = {
  key: 0,
  class: "fixed z-[9999] flex flex-col overflow-hidden bg-[#121212] text-zinc-100 shadow-2xl inset-0 sm:inset-auto sm:bottom-24 sm:right-6 sm:h-[min(560px,calc(100vh-7rem))] sm:w-[400px] sm:rounded-2xl sm:border sm:border-zinc-800"
}, Mo = { class: "relative flex shrink-0 items-center justify-between border-b border-zinc-800 px-4 py-3" }, Io = { class: "flex w-20 items-center gap-1" }, Do = { class: "absolute left-1/2 -translate-x-1/2 truncate text-sm font-medium text-white" }, Bo = { class: "flex w-20 items-center justify-end gap-1" }, qo = {
  key: 0,
  class: "flex-1 overflow-y-auto px-2 py-2"
}, Ho = {
  key: 0,
  class: "flex justify-center py-12"
}, Vo = {
  key: 1,
  class: "px-4 py-12 text-center text-sm text-zinc-500"
}, Jo = ["onClick"], Ko = { class: "min-w-0 flex-1" }, Wo = { class: "flex items-center gap-2" }, Go = { class: "truncate text-sm font-medium text-white" }, Xo = { class: "mt-0.5 text-xs text-zinc-500" }, Qo = {
  key: 0,
  class: "flex justify-center py-12"
}, Yo = {
  key: 1,
  class: "flex h-full min-h-[280px] flex-col items-center justify-center px-2 text-center"
}, Zo = ["src"], ea = { class: "text-base text-zinc-300" }, ta = { class: "mt-2 max-w-[280px] text-xl font-bold leading-snug text-white" }, na = {
  key: 0,
  class: "mt-4 max-w-[300px] text-sm leading-relaxed text-zinc-500"
}, sa = {
  key: 2,
  class: "space-y-5"
}, ra = ["innerHTML"], oa = ["innerHTML"], aa = {
  key: 0,
  class: "flex items-start"
}, ia = { class: "shrink-0 border-t border-zinc-800 px-4 py-3" }, la = {
  key: 0,
  class: "mb-2 rounded-xl bg-zinc-900 px-3 py-2 text-center text-xs text-zinc-500"
}, ca = { class: "relative flex items-center" }, da = ["placeholder", "disabled"], ua = { class: "absolute right-1.5 flex items-center gap-0.5" }, fa = ["disabled"], pa = { class: "mt-2 text-center text-[10px] text-zinc-600" }, ma = {
  __name: "FloatingChatWidget",
  props: {
    product_id: { type: String, default: "" },
    slug: { type: String, default: "" },
    ai_member_widget: { type: Object, default: null },
    user_name: { type: String, default: "" },
    theme_primary: { type: String, default: "" }
  },
  setup(e) {
    const t = e, n = S(!1), s = S("chat"), r = S([]), o = S([]), a = S(""), c = S(!1), m = S(""), l = S(!1), u = S(!1), g = S(!1), v = S(null), $ = S(null), f = S(!1), h = S(null), b = S([]), C = Z(() => t.ai_member_widget && typeof t.ai_member_widget == "object" ? t.ai_member_widget : { enabled: !1 }), L = Z(() => t.product_id || C.value.product_id || ""), U = Z(
      () => C.value.widget_color || t.theme_primary || C.value.theme_primary || "#0ea5e9"
    ), q = Z(() => !!(C.value.enabled && L.value)), W = Z(() => {
      const R = (t.user_name || "").trim();
      return R ? R.split(/\s+/)[0] : "por aqui";
    }), se = Z(
      () => s.value === "chat" && !u.value && r.value.length === 0 && !c.value
    ), M = Z(() => !c.value && !l.value);
    Et(n, async (R) => {
      R && (s.value = "chat", c.value = !1, await ie());
    });
    async function ie(R = null) {
      if (L.value) {
        u.value = !0;
        try {
          const _ = { product_id: L.value };
          R && (_.conversation_id = R);
          const { data: E } = await O.get("/api/ai-member/history", { params: _ });
          r.value = E.messages ?? [], a.value = E.conversation_id || "", c.value = !!E.archived;
        } catch {
          r.value = [], a.value = "";
        } finally {
          u.value = !1, me();
        }
      }
    }
    async function ce() {
      if (L.value) {
        g.value = !0;
        try {
          const { data: R } = await O.get("/api/ai-member/conversations", {
            params: { product_id: L.value }
          });
          o.value = R.conversations ?? [];
        } catch {
          o.value = [];
        } finally {
          g.value = !1;
        }
      }
    }
    async function A() {
      s.value = "archive", await ce();
    }
    async function p(R) {
      s.value = "archived-chat", c.value = R.archived, await ie(R.id);
    }
    async function k() {
      try {
        const { data: R } = await O.post("/api/ai-member/conversations/new", {
          product_id: L.value
        });
        a.value = R.conversation_id || "", r.value = [], c.value = !1, s.value = "chat", m.value = "";
      } catch (R) {
        alert(R?.response?.data?.message || "Não foi possível iniciar nova conversa.");
      }
    }
    async function V(R = {}) {
      const _ = (R.message ?? m.value).trim(), E = R.image_base64 || R.audio_base64;
      if (!(!_ && !E || !M.value)) {
        _ && r.value.push({
          id: `u-${Date.now()}`,
          role: "user",
          content: _,
          created_at: (/* @__PURE__ */ new Date()).toISOString()
        }), m.value = "", l.value = !0, me();
        try {
          const { data: F } = await O.post("/api/ai-member/chat", {
            product_id: L.value,
            message: _,
            ...R
          });
          if (F.conversation_id && (a.value = F.conversation_id), F.message?.content) {
            const Q = r.value[r.value.length - 1];
            Q?.role === "user" && F.message.id && (Q.id = F.message.id, Q.created_at = F.message.created_at);
          }
          F.reply && r.value.push({
            id: F.reply.id,
            role: "assistant",
            content: F.reply.content,
            created_at: F.reply.created_at
          });
        } catch (F) {
          r.value.push({
            id: `err-${Date.now()}`,
            role: "assistant",
            content: F?.response?.data?.message || "Não foi possível enviar. Tente novamente.",
            created_at: (/* @__PURE__ */ new Date()).toISOString()
          });
        } finally {
          l.value = !1, me();
        }
      }
    }
    function le() {
      if (s.value === "archived-chat") {
        A();
        return;
      }
      s.value = "chat", c.value = !1, ie();
    }
    function me() {
      sn(() => {
        v.value && (v.value.scrollTop = v.value.scrollHeight);
      });
    }
    function J(R) {
      const _ = R.target.files?.[0];
      if (!_ || !C.value.allow_image) return;
      if (_.size > 5 * 1024 * 1024) {
        alert("Imagem muito grande (máx. 5MB).");
        return;
      }
      const E = new FileReader();
      E.onload = () => {
        const F = E.result;
        typeof F == "string" && V({
          message: m.value || "Analise esta imagem.",
          image_base64: F.split(",")[1],
          image_mime: _.type
        });
      }, E.readAsDataURL(_), R.target.value = "";
    }
    async function Ee() {
      if (!(!C.value.allow_audio || !M.value)) {
        if (f.value) {
          h.value?.stop();
          return;
        }
        try {
          const R = await navigator.mediaDevices.getUserMedia({ audio: !0 });
          b.value = [];
          const _ = new MediaRecorder(R);
          h.value = _, _.ondataavailable = (E) => {
            E.data.size > 0 && b.value.push(E.data);
          }, _.onstop = async () => {
            R.getTracks().forEach((Q) => Q.stop()), f.value = !1;
            const E = new Blob(b.value, { type: "audio/webm" });
            if (E.size > 10 * 1024 * 1024) {
              alert("Áudio muito grande (máx. 10MB).");
              return;
            }
            const F = new FileReader();
            F.onload = () => {
              const Q = F.result;
              typeof Q == "string" && V({
                message: m.value,
                audio_base64: Q.split(",")[1],
                audio_mime: "audio/webm"
              });
            }, F.readAsDataURL(E);
          }, _.start(), f.value = !0;
        } catch {
          alert("Permita acesso ao microfone para enviar áudio.");
        }
      }
    }
    function re(R) {
      return R ? R.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>").replace(/\n/g, "<br>") : "";
    }
    function Ce(R) {
      if (!R) return "";
      const _ = new Date(R);
      if (Number.isNaN(_.getTime())) return "";
      const E = String(_.getDate()).padStart(2, "0"), F = String(_.getMonth() + 1).padStart(2, "0"), Q = String(_.getHours()).padStart(2, "0"), nn = String(_.getMinutes()).padStart(2, "0");
      return `${E}/${F} • ${Q}:${nn}`;
    }
    function Ie(R) {
      if (!R) return "";
      const _ = new Date(R);
      return Number.isNaN(_.getTime()) ? "" : _.toLocaleDateString("pt-BR", { day: "2-digit", month: "short", year: "numeric" });
    }
    return (R, _) => q.value ? (x(), H(Ct, {
      key: 0,
      to: "body"
    }, [
      i("button", {
        type: "button",
        class: "fixed bottom-4 right-4 z-[9998] flex h-14 w-14 items-center justify-center rounded-full shadow-lg transition hover:scale-105 focus:outline-none focus:ring-2 focus:ring-offset-2 sm:bottom-6 sm:right-6",
        style: de({ backgroundColor: U.value }),
        "aria-label": n.value ? "Fechar chat" : "Abrir chat",
        onClick: _[0] || (_[0] = (E) => n.value = !n.value)
      }, [
        C.value.widget_icon && !n.value ? (x(), y("img", {
          key: 0,
          src: C.value.widget_icon,
          alt: "",
          class: "h-8 w-8 rounded-full object-cover"
        }, null, 8, Lo)) : n.value ? (x(), H(w(et), {
          key: 2,
          class: "h-7 w-7 text-white"
        })) : (x(), H(w(Pe), {
          key: 1,
          class: "h-7 w-7 text-white"
        }))
      ], 12, Fo),
      T(rn, {
        "enter-active-class": "transition duration-200 ease-out",
        "enter-from-class": "opacity-0 translate-y-4 sm:translate-y-0 sm:translate-x-4",
        "enter-to-class": "opacity-100 translate-y-0",
        "leave-active-class": "transition duration-150 ease-in",
        "leave-from-class": "opacity-100",
        "leave-to-class": "opacity-0 translate-y-4"
      }, {
        default: on(() => [
          n.value ? (x(), y("div", jo, [
            i("header", Mo, [
              i("div", Io, [
                s.value !== "chat" ? (x(), y("button", {
                  key: 0,
                  type: "button",
                  class: "rounded-lg p-2 text-zinc-400 transition hover:bg-zinc-800 hover:text-white",
                  "aria-label": "Voltar",
                  onClick: le
                }, [
                  T(w(Gs), { class: "h-5 w-5" })
                ])) : (x(), y("button", {
                  key: 1,
                  type: "button",
                  class: "rounded-lg p-2 text-zinc-400 transition hover:bg-zinc-800 hover:text-white",
                  "aria-label": "Conversas anteriores",
                  onClick: A
                }, [
                  T(w(Ws), { class: "h-5 w-5" })
                ]))
              ]),
              i("h2", Do, N(s.value === "archive" ? "Conversas" : C.value.name || "Assistente"), 1),
              i("div", Bo, [
                s.value === "chat" && r.value.length > 0 ? (x(), y("button", {
                  key: 0,
                  type: "button",
                  class: "rounded-lg p-2 text-zinc-400 transition hover:bg-zinc-800 hover:text-white",
                  "aria-label": "Nova conversa",
                  onClick: k
                }, [
                  T(w(Ze), { class: "h-5 w-5" })
                ])) : j("", !0),
                i("button", {
                  type: "button",
                  class: "rounded-lg p-2 text-zinc-400 transition hover:bg-zinc-800 hover:text-white sm:hidden",
                  "aria-label": "Fechar",
                  onClick: _[1] || (_[1] = (E) => n.value = !1)
                }, [
                  T(w(et), { class: "h-5 w-5" })
                ])
              ])
            ]),
            s.value === "archive" ? (x(), y("div", qo, [
              g.value ? (x(), y("div", Ho, [
                T(w(ne), { class: "h-6 w-6 animate-spin text-zinc-500" })
              ])) : o.value.length === 0 ? (x(), y("div", Vo, " Nenhuma conversa anterior. ")) : j("", !0),
              (x(!0), y(he, null, Je(o.value, (E) => (x(), y("button", {
                key: E.id,
                type: "button",
                class: "mb-1 flex w-full items-start gap-3 rounded-xl px-3 py-3 text-left transition hover:bg-zinc-800/80",
                onClick: (F) => p(E)
              }, [
                i("div", {
                  class: "mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full",
                  style: de({ backgroundColor: `${U.value}33` })
                }, [
                  T(w(Pe), {
                    class: "h-4 w-4",
                    style: de({ color: U.value })
                  }, null, 8, ["style"])
                ], 4),
                i("div", Ko, [
                  i("div", Wo, [
                    i("span", Go, N(E.title), 1),
                    E.archived ? j("", !0) : (x(), y("span", {
                      key: 0,
                      class: "shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium",
                      style: de({ backgroundColor: `${U.value}22`, color: U.value })
                    }, " Atual ", 4))
                  ]),
                  i("p", Xo, N(E.messages_count) + " mensagem" + N(E.messages_count === 1 ? "" : "ens") + " · " + N(Ie(E.last_message_at)), 1)
                ])
              ], 8, Jo))), 128)),
              i("button", {
                type: "button",
                class: "mx-2 mt-2 flex w-[calc(100%-1rem)] items-center justify-center gap-2 rounded-xl border border-zinc-700 py-2.5 text-sm font-medium text-zinc-300 transition hover:bg-zinc-800",
                onClick: k
              }, [
                T(w(Ze), { class: "h-4 w-4" }),
                _[6] || (_[6] = B(" Nova conversa ", -1))
              ])
            ])) : (x(), y(he, { key: 1 }, [
              i("div", {
                ref_key: "messagesEl",
                ref: v,
                class: "flex-1 overflow-y-auto px-4 py-4"
              }, [
                u.value ? (x(), y("div", Qo, [
                  T(w(ne), { class: "h-6 w-6 animate-spin text-zinc-500" })
                ])) : se.value ? (x(), y("div", Yo, [
                  i("div", {
                    class: "mb-5 flex h-16 w-16 items-center justify-center rounded-full shadow-lg",
                    style: de({ background: `linear-gradient(135deg, ${U.value}, #1e1b4b)` })
                  }, [
                    C.value.widget_icon ? (x(), y("img", {
                      key: 0,
                      src: C.value.widget_icon,
                      alt: "",
                      class: "h-10 w-10 rounded-full object-cover"
                    }, null, 8, Zo)) : (x(), H(w(tn), {
                      key: 1,
                      class: "h-8 w-8 text-white"
                    }))
                  ], 4),
                  i("p", ea, " Olá, " + N(W.value) + "! ", 1),
                  i("h3", ta, N(C.value.intro_headline || "Como posso ajudar você hoje?"), 1),
                  C.value.welcome_message ? (x(), y("p", na, N(C.value.welcome_message), 1)) : j("", !0)
                ])) : (x(), y("div", sa, [
                  (x(!0), y(he, null, Je(r.value, (E) => (x(), y("div", {
                    key: E.id,
                    class: P(["flex flex-col", E.role === "user" ? "items-end" : "items-start"])
                  }, [
                    E.role === "user" ? (x(), y("div", {
                      key: 0,
                      class: "max-w-[85%] rounded-2xl rounded-br-md bg-zinc-800 px-4 py-2.5 text-sm leading-relaxed text-zinc-100",
                      innerHTML: re(E.content)
                    }, null, 8, ra)) : (x(), y("div", {
                      key: 1,
                      class: "max-w-[92%] text-sm leading-relaxed text-zinc-200",
                      innerHTML: re(E.content)
                    }, null, 8, oa)),
                    i("span", {
                      class: P(["mt-1.5 text-[11px] text-zinc-600", E.role === "user" ? "text-right" : "text-left"])
                    }, N(Ce(E.created_at)), 3)
                  ], 2))), 128)),
                  l.value ? (x(), y("div", aa, [
                    T(w(ne), { class: "h-4 w-4 animate-spin text-zinc-500" })
                  ])) : j("", !0)
                ]))
              ], 512),
              i("footer", ia, [
                c.value ? (x(), y("div", la, " Conversa arquivada — somente leitura ")) : j("", !0),
                i("div", ca, [
                  i("input", {
                    ref_key: "imageInput",
                    ref: $,
                    type: "file",
                    accept: "image/*",
                    class: "hidden",
                    onChange: J
                  }, null, 544),
                  D(i("textarea", {
                    "onUpdate:modelValue": _[2] || (_[2] = (E) => m.value = E),
                    rows: "1",
                    class: "max-h-24 min-h-[44px] w-full resize-none rounded-full border border-zinc-700 bg-transparent py-2.5 pl-4 pr-24 text-sm text-zinc-100 placeholder:text-zinc-500 focus:border-zinc-600 focus:outline-none disabled:opacity-50",
                    placeholder: c.value ? "Conversa arquivada" : "Pergunte alguma coisa",
                    disabled: !M.value,
                    onKeydown: _[3] || (_[3] = an(ln((E) => V(), ["exact", "prevent"]), ["enter"]))
                  }, null, 40, da), [
                    [Y, m.value]
                  ]),
                  i("div", ua, [
                    C.value.allow_image && M.value ? (x(), y("button", {
                      key: 0,
                      type: "button",
                      class: "rounded-full p-2 text-zinc-500 transition hover:text-zinc-300",
                      onClick: _[4] || (_[4] = (E) => $.value?.click())
                    }, [
                      T(w(Zt), { class: "h-4 w-4" })
                    ])) : j("", !0),
                    C.value.allow_audio && M.value ? (x(), y("button", {
                      key: 1,
                      type: "button",
                      class: P(["rounded-full p-2 transition", f.value ? "text-red-400" : "text-zinc-500 hover:text-zinc-300"]),
                      onClick: Ee
                    }, [
                      f.value ? (x(), H(w(nr), {
                        key: 0,
                        class: "h-4 w-4"
                      })) : (x(), H(w(en), {
                        key: 1,
                        class: "h-4 w-4"
                      }))
                    ], 2)) : j("", !0),
                    i("button", {
                      type: "button",
                      class: "rounded-full p-2 text-zinc-300 transition hover:text-white disabled:opacity-40",
                      disabled: !M.value,
                      onClick: _[5] || (_[5] = (E) => V())
                    }, [
                      T(w(er), { class: "h-4 w-4" })
                    ], 8, fa)
                  ])
                ]),
                i("p", pa, " Tecnologia ✦ " + N(C.value.name || "IA"), 1)
              ])
            ], 64))
          ])) : j("", !0)
        ]),
        _: 1
      })
    ])) : j("", !0);
  }
}, ha = { class: "mx-auto max-w-3xl space-y-8" }, ga = { class: "flex items-start gap-4" }, ba = { class: "flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-indigo-100 dark:bg-indigo-950" }, xa = { class: "rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900/40" }, va = { class: "mb-4 flex items-center gap-2" }, ya = {
  key: 0,
  class: "ml-auto inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
}, wa = {
  key: 1,
  class: "ml-auto inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-300"
}, ka = { class: "mb-4 text-sm text-zinc-600 dark:text-zinc-400" }, _a = {
  href: "https://openrouter.ai/keys",
  target: "_blank",
  rel: "noopener",
  class: "inline-flex items-center gap-0.5 text-indigo-600 hover:underline"
}, za = {
  key: 0,
  class: "flex justify-center py-6"
}, Ra = {
  key: 1,
  class: "space-y-4"
}, Ea = ["placeholder"], Ca = { class: "flex items-center gap-2 cursor-pointer" }, Sa = { class: "flex flex-wrap gap-2" }, Aa = ["disabled"], Oa = { key: 1 }, Ta = ["disabled"], $a = { key: 1 }, Na = { class: "rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900/40" }, Pa = { class: "mb-4 flex items-center gap-2" }, Ua = {
  href: "/produtos",
  class: "mt-4 inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:underline"
}, Fa = {
  key: 0,
  class: "rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-950/40"
}, La = {
  key: 1,
  class: "rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950/40"
}, ja = "w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800", Ma = {
  __name: "SettingsPage",
  setup(e) {
    const t = S({
      configured: !1,
      is_active: !1,
      has_token: !1,
      last_tested_at: null,
      last_error: null
    }), n = S({ api_key: "", is_active: !0 }), s = S(!0), r = S(!1), o = S(!1), a = S(""), c = S("");
    async function m() {
      s.value = !0, c.value = "";
      try {
        const { data: g } = await O.get("/ai-member/connection");
        t.value = g.connection ?? t.value, n.value.is_active = t.value.is_active ?? !0;
      } catch (g) {
        c.value = g?.response?.data?.message || "Não foi possível carregar a conexão.";
      } finally {
        s.value = !1;
      }
    }
    async function l() {
      r.value = !0, a.value = "", c.value = "";
      try {
        const { data: g } = await O.put("/ai-member/connection", {
          api_key: n.value.api_key?.trim() || void 0,
          is_active: n.value.is_active
        });
        t.value = g.connection ?? t.value, n.value.api_key = "", a.value = "Conexão salva com sucesso.";
      } catch (g) {
        c.value = g?.response?.data?.message || "Falha ao salvar.";
      } finally {
        r.value = !1;
      }
    }
    async function u() {
      o.value = !0, a.value = "", c.value = "";
      try {
        const { data: g } = await O.post("/ai-member/connection/test");
        g?.success ? (a.value = g.message || "Conexão OK.", await m()) : c.value = g?.message || "Falha no teste.";
      } catch (g) {
        c.value = g?.response?.data?.message || "Falha no teste.";
      } finally {
        o.value = !1;
      }
    }
    return St(m), (g, v) => (x(), y("div", ha, [
      i("div", ga, [
        i("div", ba, [
          T(w(Qe), { class: "h-8 w-8 text-indigo-600" })
        ]),
        v[2] || (v[2] = i("div", null, [
          i("h1", { class: "text-2xl font-bold text-zinc-900 dark:text-white" }, "AI Member"),
          i("p", { class: "mt-1 text-sm text-zinc-600 dark:text-zinc-400" }, " Chat de suporte com IA na área de membros. Configure o OpenRouter aqui e o agente em cada produto. ")
        ], -1))
      ]),
      i("section", xa, [
        i("div", va, [
          T(w(Ys), { class: "h-5 w-5 text-indigo-600" }),
          v[5] || (v[5] = i("h2", { class: "text-lg font-semibold text-zinc-900 dark:text-white" }, "1. OpenRouter", -1)),
          t.value.configured && t.value.is_active ? (x(), y("span", ya, [
            T(w(Qs), { class: "h-3.5 w-3.5" }),
            v[3] || (v[3] = B(" Conectado ", -1))
          ])) : (x(), y("span", wa, [
            T(w(Ye), { class: "h-3.5 w-3.5" }),
            v[4] || (v[4] = B(" Pendente ", -1))
          ]))
        ]),
        i("p", ka, [
          v[7] || (v[7] = B(" Obtenha sua API key em ", -1)),
          i("a", _a, [
            v[6] || (v[6] = B(" openrouter.ai/keys ", -1)),
            T(w(Ne), { class: "h-3.5 w-3.5" })
          ])
        ]),
        s.value ? (x(), y("div", za, [
          T(w(ne), { class: "h-6 w-6 animate-spin text-zinc-400" })
        ])) : (x(), y("div", Ra, [
          i("div", null, [
            v[8] || (v[8] = i("label", { class: "mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300" }, "API Key", -1)),
            D(i("input", {
              "onUpdate:modelValue": v[0] || (v[0] = ($) => n.value.api_key = $),
              type: "password",
              class: P(ja),
              placeholder: t.value.has_token ? "•••••••• (deixe em branco para manter)" : "sk-or-..."
            }, null, 8, Ea), [
              [Y, n.value.api_key]
            ])
          ]),
          i("label", Ca, [
            D(i("input", {
              "onUpdate:modelValue": v[1] || (v[1] = ($) => n.value.is_active = $),
              type: "checkbox",
              class: "rounded"
            }, null, 512), [
              [ye, n.value.is_active]
            ]),
            v[9] || (v[9] = i("span", { class: "text-sm text-zinc-700 dark:text-zinc-300" }, "Integração ativa", -1))
          ]),
          i("div", Sa, [
            i("button", {
              type: "button",
              class: "rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium dark:border-zinc-600",
              disabled: o.value || !t.value.configured,
              onClick: u
            }, [
              o.value ? (x(), H(w(ne), {
                key: 0,
                class: "inline h-4 w-4 animate-spin"
              })) : (x(), y("span", Oa, "Testar conexão"))
            ], 8, Aa),
            i("button", {
              type: "button",
              class: "rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50",
              disabled: r.value,
              onClick: l
            }, [
              r.value ? (x(), H(w(ne), {
                key: 0,
                class: "inline h-4 w-4 animate-spin"
              })) : (x(), y("span", $a, "Salvar"))
            ], 8, Ta)
          ])
        ]))
      ]),
      i("section", Na, [
        i("div", Pa, [
          T(w(Yt), { class: "h-5 w-5 text-indigo-600" }),
          v[10] || (v[10] = i("h2", { class: "text-lg font-semibold text-zinc-900 dark:text-white" }, "2. Agente por produto", -1))
        ]),
        v[12] || (v[12] = i("p", { class: "text-sm text-zinc-600 dark:text-zinc-400" }, [
          B(" Abra a edição de um produto do tipo "),
          i("strong", null, "área de membros"),
          B(" e vá na aba "),
          i("strong", null, "AI Member"),
          B(" para ativar o widget, personalizar o agente e sincronizar o conhecimento das aulas. ")
        ], -1)),
        i("a", Ua, [
          v[11] || (v[11] = B(" Ir para Produtos ", -1)),
          T(w(Ne), { class: "h-3.5 w-3.5" })
        ])
      ]),
      a.value ? (x(), y("div", Fa, N(a.value), 1)) : j("", !0),
      c.value ? (x(), y("div", La, N(c.value), 1)) : j("", !0)
    ]));
  }
};
window.__GETFY_PLUGIN_UI__ = window.__GETFY_PLUGIN_UI__ || {};
window.__GETFY_PLUGIN_UI__["ai-member"] = {
  IntegrationsSidebar: kr,
  ProductAgentPanel: Uo,
  FloatingChatWidget: ma,
  SettingsPage: Ma
};
export {
  ma as FloatingChatWidget,
  kr as IntegrationsSidebar,
  Uo as ProductAgentPanel,
  Ma as SettingsPage
};
