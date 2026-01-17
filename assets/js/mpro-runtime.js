(function () {
  'use strict';

  const META_NAME = 'mpro-api-base';
  const STORAGE_KEY = 'mproApiBaseUrl';

  function safeStorageGet(key) {
    try {
      return localStorage.getItem(key);
    } catch (_) {
      return null;
    }
  }

  function safeStorageSet(key, value) {
    try {
      localStorage.setItem(key, value);
      return true;
    } catch (_) {
      return false;
    }
  }

  function normalizeBaseUrl(value) {
    const raw = (value || '').toString().trim();
    if (!raw) return '';
    try {
      const url = new URL(raw, window.location.origin + '/');
      return url.href.endsWith('/') ? url.href : url.href + '/';
    } catch (_) {
      return '';
    }
  }

  function resolveApiBaseUrl() {
    const preconfigured =
      normalizeBaseUrl(window.MPRO_CONFIG && window.MPRO_CONFIG.apiBaseUrl) ||
      normalizeBaseUrl(window.MPRO && window.MPRO.apiBaseUrl);
    if (preconfigured) return preconfigured;

    const meta = document.querySelector(`meta[name="${META_NAME}"]`);
    const metaValue = meta ? meta.getAttribute('content') : '';
    const fromMeta = normalizeBaseUrl(metaValue);
    if (fromMeta) return fromMeta;

    const stored = normalizeBaseUrl(safeStorageGet(STORAGE_KEY));
    if (stored) return stored;

    return window.location.origin + '/';
  }

  function withoutLeadingSlash(path) {
    return (path || '').toString().replace(/^\/+/, '');
  }

  function apiUrl(path) {
    return new URL(withoutLeadingSlash(path), apiBaseUrl).toString();
  }

  function buildApiUrl(path, params) {
    const url = new URL(withoutLeadingSlash(path), apiBaseUrl);
    if (params && typeof params === 'object') {
      Object.entries(params).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') return;
        url.searchParams.set(key, String(value));
      });
    }
    return url.toString();
  }

  function setApiBaseUrl(value) {
    const normalized = normalizeBaseUrl(value);
    if (!normalized) return false;
    return safeStorageSet(STORAGE_KEY, normalized);
  }

  const apiBaseUrl = resolveApiBaseUrl();

  window.MPRO = Object.assign(window.MPRO || {}, {
    apiBaseUrl,
    apiUrl,
    buildApiUrl,
    setApiBaseUrl
  });
})();
