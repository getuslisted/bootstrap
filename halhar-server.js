#!/usr/bin/env node
const http = require('node:http');
const { URL } = require('node:url');
const fs = require('node:fs');
const fsp = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');

const halharConfig = require('./js/halhar-config.js');

const PORT = Number.parseInt(process.env.PORT || process.env.HALHAR_PORT || '8080', 10);
const CACHE_TTL_MS = 10 * 60 * 1000;
const FETCH_TIMEOUT_MS = 8000;
const MAX_ITEMS = 60;

const baseDir = path.resolve(process.cwd());
const CACHE_DIR = path.join(baseDir, '.cache');
const FEED_CACHE_DIR = path.join(CACHE_DIR, 'feeds');
const POLL_CACHE_FILE = path.join(CACHE_DIR, 'polls.json');

const cacheDirReady = fsp
  .mkdir(FEED_CACHE_DIR, { recursive: true })
  .catch((error) => {
    console.warn('Unable to initialize cache directory', error);
  });

const MIME_TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.htm': 'text/html; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.mjs': 'application/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.webp': 'image/webp',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon',
  '.txt': 'text/plain; charset=utf-8'
};

function getFeedCachePath(url) {
  const hash = crypto.createHash('sha256').update(url).digest('hex');
  return path.join(FEED_CACHE_DIR, `${hash}.json`);
}

async function loadFeedCacheFromDisk(url) {
  try {
    await cacheDirReady;
    const filePath = getFeedCachePath(url);
    const raw = await fsp.readFile(filePath, 'utf8');
    return JSON.parse(raw);
  } catch (error) {
    return null;
  }
}

async function persistFeedCache(url, entry) {
  try {
    await cacheDirReady;
    const payload = {
      data: entry.data,
      fetchedAt: entry.fetchedAt,
      lastSuccessAt: entry.lastSuccessAt,
      failCount: entry.failCount,
      unloaded: entry.unloaded,
      lastError: entry.lastError ? entry.lastError.message : null
    };
    const filePath = getFeedCachePath(url);
    await fsp.writeFile(filePath, JSON.stringify(payload), 'utf8');
  } catch (error) {
    console.warn('Failed to persist feed cache for', url, error.message);
  }
}

async function loadPollCacheFromDisk() {
  try {
    await cacheDirReady;
    const raw = await fsp.readFile(POLL_CACHE_FILE, 'utf8');
    return JSON.parse(raw);
  } catch (error) {
    return null;
  }
}

async function persistPollCache(entry) {
  try {
    await cacheDirReady;
    const payload = {
      data: entry.data,
      fetchedAt: entry.fetchedAt,
      lastSuccessAt: entry.lastSuccessAt,
      failCount: entry.failCount,
      unloaded: entry.unloaded,
      lastError: entry.lastError ? entry.lastError.message : null
    };
    await fsp.writeFile(POLL_CACHE_FILE, JSON.stringify(payload), 'utf8');
  } catch (error) {
    console.warn('Failed to persist poll cache', error.message);
  }
}

function hydrateFeedEntryFromDisk(entry, payload) {
  if (!payload || typeof payload !== 'object') {
    return false;
  }
  if (payload.data) {
    entry.data = payload.data;
  }
  const fetchedAt =
    typeof payload.fetchedAt === 'number'
      ? payload.fetchedAt
      : payload.fetchedAt
        ? Date.parse(payload.fetchedAt)
        : null;
  if (Number.isFinite(fetchedAt)) {
    entry.fetchedAt = fetchedAt;
  }
  const lastSuccessAt =
    typeof payload.lastSuccessAt === 'number'
      ? payload.lastSuccessAt
      : payload.lastSuccessAt
        ? Date.parse(payload.lastSuccessAt)
        : null;
  if (Number.isFinite(lastSuccessAt)) {
    entry.lastSuccessAt = lastSuccessAt;
  }
  entry.failCount = typeof payload.failCount === 'number' ? payload.failCount : entry.failCount;
  entry.unloaded = Boolean(payload.unloaded);
  entry.lastError = payload.lastError ? new Error(payload.lastError) : null;
  return Boolean(entry.data);
}

function hydratePollEntryFromDisk(entry, payload) {
  if (!payload || typeof payload !== 'object') {
    return false;
  }
  if (payload.data) {
    entry.data = payload.data;
  }
  const fetchedAt =
    typeof payload.fetchedAt === 'number'
      ? payload.fetchedAt
      : payload.fetchedAt
        ? Date.parse(payload.fetchedAt)
        : null;
  if (Number.isFinite(fetchedAt)) {
    entry.fetchedAt = fetchedAt;
  }
  const lastSuccessAt =
    typeof payload.lastSuccessAt === 'number'
      ? payload.lastSuccessAt
      : payload.lastSuccessAt
        ? Date.parse(payload.lastSuccessAt)
        : null;
  if (Number.isFinite(lastSuccessAt)) {
    entry.lastSuccessAt = lastSuccessAt;
  }
  entry.failCount = typeof payload.failCount === 'number' ? payload.failCount : entry.failCount;
  entry.unloaded = Boolean(payload.unloaded);
  entry.lastError = payload.lastError ? new Error(payload.lastError) : null;
  return Boolean(entry.data);
}

const cache = new Map();
const pollCacheEntry = {
  data: null,
  fetchedAt: 0,
  lastSuccessAt: 0,
  failCount: 0,
  lastError: null,
  fetching: null,
  unloaded: false,
  lastAttempt: 0,
  diskLoaded: false
};

const POLL_SOURCE_URL = 'https://www.realclearpolitics.com/epolls/latest_polls.html';
const POLL_BASE_URL = 'https://www.realclearpolitics.com';
const POLLSTER_METADATA = [
  {
    id: 'rasmussen',
    name: 'Rasmussen Reports',
    category: 'mainstream',
    website: 'https://www.rasmussenreports.com/',
    favicon: 'https://www.rasmussenreports.com/favicon.ico',
    aliases: ['rasmussen', 'rasmussen reports', 'pulse opinion research', 'rasmussen/pulse opinion research']
  },
  {
    id: 'foxnews',
    name: 'Fox News',
    category: 'mainstream',
    website: 'https://www.foxnews.com/',
    favicon: 'https://www.foxnews.com/favicon.ico',
    aliases: ['fox news', 'fox news poll']
  },
  {
    id: 'trafalgar',
    name: 'The Trafalgar Group',
    category: 'independent',
    website: 'https://www.thetrafalgargroup.org/',
    favicon: 'https://www.thetrafalgargroup.org/wp-content/uploads/2018/10/cropped-logo-192x192.png',
    aliases: ['trafalgar', 'the trafalgar group', 'trafalgar group']
  },
  {
    id: 'remington',
    name: 'Remington Research Group',
    category: 'independent',
    website: 'https://www.remingtonresearchgroup.com/',
    favicon: 'https://www.remingtonresearchgroup.com/wp-content/uploads/2017/01/cropped-rrg-favicon-192x192.png',
    aliases: ['remington research group', 'remington research']
  },
  {
    id: 'insideradvantage',
    name: 'InsiderAdvantage',
    category: 'independent',
    website: 'https://insideradvantage.com/',
    favicon: 'https://insideradvantage.com/wp-content/uploads/2019/05/cropped-ia-favicon-192x192.png',
    aliases: ['insideradvantage', 'insider advantage']
  },
  {
    id: 'cygnal',
    name: 'Cygnal',
    category: 'independent',
    website: 'https://www.cygn.al/',
    favicon: 'https://www.cygn.al/wp-content/uploads/2020/08/cropped-Cygnal-Favicon-192x192.png',
    aliases: ['cygnal']
  },
  {
    id: 'mclaughlin',
    name: 'McLaughlin & Associates',
    category: 'independent',
    website: 'https://mclaughlinonline.com/',
    favicon: 'https://mclaughlinonline.com/wp-content/uploads/2019/09/cropped-favicon-192x192.png',
    aliases: ['mclaughlin', 'mclaughlin & associates', 'mclaughlin and associates']
  },
  {
    id: 'rmgresearch',
    name: 'RMG Research',
    category: 'independent',
    website: 'https://scottrasmussen.com/',
    favicon: 'https://scottrasmussen.com/wp-content/uploads/2020/09/cropped-RMG-Research-Favicon-192x192.png',
    aliases: ['rmg research', 'rmg']
  },
  {
    id: 'susquehanna',
    name: 'Susquehanna Polling & Research',
    category: 'independent',
    website: 'https://susquehannapolling.com/',
    favicon: 'https://susquehannapolling.com/wp-content/uploads/2019/01/cropped-favicon-192x192.png',
    aliases: ['susquehanna', 'susquehanna polling & research', 'susquehanna polling']
  },
  {
    id: 'ewtn',
    name: 'EWTN/RealClear Opinion Research',
    category: 'faith',
    website: 'https://www.ewtn.com/',
    favicon: 'https://www.ewtn.com/favicon.ico',
    aliases: ['ewtn', 'ewtn/news realclear opinion research', 'realclear opinion research', 'ewtn/realclear opinion research']
  },
  {
    id: 'catholicvote',
    name: 'CatholicVote',
    category: 'faith',
    website: 'https://catholicvote.org/',
    favicon: 'https://catholicvote.org/wp-content/uploads/2020/03/cropped-favicon-192x192.png',
    aliases: ['catholicvote', 'catholic vote']
  },
  {
    id: 'americansforprosperity',
    name: 'Americans for Prosperity',
    category: 'independent',
    website: 'https://americansforprosperity.org/',
    favicon: 'https://americansforprosperity.org/wp-content/uploads/2018/09/cropped-favicon-192x192.png',
    aliases: ['americans for prosperity', 'afp']
  }
];

const pollsterLookup = new Map();
const pollsterAliasIndex = [];

for (const meta of POLLSTER_METADATA) {
  const keys = new Set();
  keys.add(meta.name);
  if (meta.aliases) {
    meta.aliases.forEach((alias) => keys.add(alias));
  }
  keys.forEach((key) => {
    const normalized = key
      .toString()
      .toLowerCase()
      .replace(/&/g, 'and')
      .replace(/[^a-z0-9]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
    if (!normalized) return;
    pollsterLookup.set(normalized, meta);
    pollsterAliasIndex.push({ key: normalized, meta });
  });
}

const HTML_ENTITIES = {
  amp: '&',
  lt: '<',
  gt: '>',
  quot: '"',
  apos: "'",
  nbsp: ' '
};

function normalizeUrl(value) {
  if (!value) return '';
  const trimmed = value.trim();
  if (/^https?:\/\//i.test(trimmed)) {
    return trimmed;
  }
  return `https://${trimmed.replace(/^\/\//, '')}`;
}

const allowedFeedList = Array.isArray(halharConfig.allowedFeedUrls) ? halharConfig.allowedFeedUrls : [];
const ALLOWED_FEED_URLS = new Set(
  allowedFeedList
    .map((url) => {
      try {
        return normalizeUrl(url);
      } catch (error) {
        return '';
      }
    })
    .filter((url) => typeof url === 'string' && url.startsWith('http'))
);

function decodeHtmlEntities(input = '') {
  return input.replace(/&(#x?[0-9a-fA-F]+|[a-zA-Z]+);/g, (_, entity) => {
    const lower = entity.toLowerCase();
    if (HTML_ENTITIES[lower]) {
      return HTML_ENTITIES[lower];
    }
    if (lower.startsWith('#x')) {
      const codePoint = Number.parseInt(lower.slice(2), 16);
      return Number.isNaN(codePoint) ? '' : String.fromCodePoint(codePoint);
    }
    if (lower.startsWith('#')) {
      const codePoint = Number.parseInt(lower.slice(1), 10);
      return Number.isNaN(codePoint) ? '' : String.fromCodePoint(codePoint);
    }
    return '';
  });
}

function stripTags(html = '') {
  return html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
}

function decodeCdata(value = '') {
  return value.replace(/<!\[CDATA\[([\s\S]*?)]]>/gi, '$1');
}

function sanitize(value = '') {
  return decodeHtmlEntities(decodeCdata(value)).trim();
}

function extractTag(xml, tagName) {
  const escaped = tagName.replace(':', ':[^>]*?');
  const pattern = new RegExp(`<${escaped}[^>]*>([\\s\\S]*?)<\/${escaped}>`, 'i');
  const match = pattern.exec(xml);
  return match ? match[1] : '';
}

function extractTags(xml, tagName) {
  const escaped = tagName.replace(':', ':[^>]*?');
  const pattern = new RegExp(`<${escaped}[^>]*>([\\s\\S]*?)<\/${escaped}>`, 'gi');
  const results = [];
  let match;
  while ((match = pattern.exec(xml)) !== null) {
    results.push(match[1]);
  }
  return results;
}

function matchAttribute(xml, tagPattern, attribute) {
  const pattern = new RegExp(`<${tagPattern}[^>]*${attribute}=["']([^"']+)["'][^>]*>`, 'i');
  const match = pattern.exec(xml);
  return match ? match[1] : '';
}

function extractFirstImageFromHtml(html = '') {
  const match = html.match(/<img[^>]+src=["']([^"']+)["']/i);
  return match ? match[1] : '';
}

function resolveUrl(url, base) {
  if (!url) return '';
  try {
    return new URL(url, base || undefined).toString();
  } catch (error) {
    return url;
  }
}

function parseDateValue(value) {
  if (!value) return null;
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return null;
  }
  return date.toISOString();
}

function detectYouTubeId(value = '') {
  const patterns = [
    /youtu\.be\/([\w-]{11})/i,
    /youtube\.com\/watch\?[^#?]*v=([\w-]{11})/i,
    /youtube\.com\/embed\/([\w-]{11})/i,
    /youtube\.com\/shorts\/([\w-]{11})/i
  ];
  for (const pattern of patterns) {
    const match = value.match(pattern);
    if (match) {
      return match[1];
    }
  }
  return '';
}

function isYoutubeUrl(url = '') {
  try {
    const {hostname} = new URL(url);
    return hostname.toLowerCase().includes('youtube.com') || hostname.toLowerCase().includes('youtu.be');
  } catch (error) {
    return false;
  }
}

function normalizePollsterKey(value = '') {
  return value
    .toString()
    .toLowerCase()
    .replace(/&/g, 'and')
    .replace(/[^a-z0-9]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function resolvePollsterMeta(rawName = '') {
  const cleaned = normalizePollsterKey(decodeHtmlEntities(stripTags(rawName || '')));
  if (!cleaned) return null;
  if (pollsterLookup.has(cleaned)) {
    return pollsterLookup.get(cleaned);
  }
  for (const entry of pollsterAliasIndex) {
    if (cleaned.includes(entry.key)) {
      return entry.meta;
    }
  }
  return null;
}

function sanitizeText(value = '') {
  return decodeHtmlEntities(stripTags(value || '')).trim();
}

function parseResultCell(text = '') {
  const cleaned = sanitizeText(text).replace(/%/g, '').trim();
  if (!cleaned) return [];
  const segments = cleaned.split(/,\s*/);
  const items = [];
  for (const segment of segments) {
    const match = segment.match(/(.+?)\s+(-?\d+(?:\.\d+)?)/);
    if (match) {
      const label = match[1].trim();
      const value = Number.parseFloat(match[2]);
      if (!Number.isNaN(value)) {
        items.push({ label, value });
      }
    }
  }
  return items;
}

function parseSampleInfo(text = '') {
  const cleaned = sanitizeText(text);
  if (!cleaned) {
    return { sampleSize: null, population: '' };
  }
  const sizeMatch = cleaned.match(/(\d{3,5})/);
  const sampleSize = sizeMatch ? Number.parseInt(sizeMatch[1], 10) : null;
  const populationMatch = cleaned.match(/\b(LV|RV|A|Likely Voters|Registered Voters|Adults|Voters|Likely GOP Primary Voters|Likely Voters GOP|Likely Voters DEM)\b/i);
  const population = populationMatch ? populationMatch[0] : '';
  return { sampleSize: Number.isNaN(sampleSize) ? null : sampleSize, population: population.trim() };
}

function parseSpreadText(text = '', results = []) {
  const cleaned = sanitizeText(text);
  if (!cleaned) {
    if (results.length >= 2) {
      const sorted = [...results].sort((a, b) => (b.value || 0) - (a.value || 0));
      if (sorted[0] && sorted[1]) {
        const margin = Number.parseFloat((sorted[0].value - sorted[1].value).toFixed(1));
        if (Number.isFinite(margin) && Math.abs(margin) > 0) {
          return {
            text: `${sorted[0].label} ${margin > 0 ? '+' : ''}${margin}`,
            leader: sorted[0].label,
            margin,
            direction: margin > 0 ? 'lead' : margin < 0 ? 'trail' : 'tie'
          };
        }
      }
    }
    return null;
  }
  if (/tie/i.test(cleaned)) {
    return { text: cleaned, leader: null, margin: 0, direction: 'tie' };
  }
  const match = cleaned.match(/(.+?)\s*([+-]?\d+(?:\.\d+)?)/);
  if (match) {
    const leader = match[1].trim();
    const margin = Number.parseFloat(match[2]);
    return {
      text: cleaned,
      leader,
      margin: Number.isNaN(margin) ? null : margin,
      direction: Number.isNaN(margin) ? null : margin > 0 ? 'lead' : margin < 0 ? 'trail' : 'tie'
    };
  }
  return { text: cleaned, leader: null, margin: null, direction: null };
}

function parseDateRangeText(text = '') {
  const cleaned = sanitizeText(text).replace(/\s+/g, ' ').trim();
  if (!cleaned) {
    return { dateRange: '', startDate: null, endDate: null };
  }
  const now = new Date();
  const year = now.getUTCFullYear();
  const rangeMatch = cleaned.match(/(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\s*(?:-|to)\s*(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?/);
  let startDate = null;
  let endDate = null;
  if (rangeMatch) {
    const startMonth = Number.parseInt(rangeMatch[1], 10) - 1;
    const startDay = Number.parseInt(rangeMatch[2], 10);
    const startYear = rangeMatch[3] ? Number.parseInt(rangeMatch[3], 10) + (rangeMatch[3].length === 2 ? 2000 : 0) : year;
    const endMonth = Number.parseInt(rangeMatch[4], 10) - 1;
    const endDay = Number.parseInt(rangeMatch[5], 10);
    let endYear = rangeMatch[6] ? Number.parseInt(rangeMatch[6], 10) + (rangeMatch[6].length === 2 ? 2000 : 0) : startYear;
    if (!rangeMatch[6] && startMonth > endMonth) {
      endYear += 1;
    }
    const start = new Date(Date.UTC(startYear, startMonth, startDay));
    const end = new Date(Date.UTC(endYear, endMonth, endDay));
    startDate = Number.isNaN(start.getTime()) ? null : start.toISOString();
    endDate = Number.isNaN(end.getTime()) ? null : end.toISOString();
  } else {
    const singleMatch = cleaned.match(/(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?/);
    if (singleMatch) {
      const month = Number.parseInt(singleMatch[1], 10) - 1;
      const day = Number.parseInt(singleMatch[2], 10);
      const parsedYear = singleMatch[3]
        ? Number.parseInt(singleMatch[3], 10) + (singleMatch[3].length === 2 ? 2000 : 0)
        : year;
      const value = new Date(Date.UTC(parsedYear, month, day));
      const iso = Number.isNaN(value.getTime()) ? null : value.toISOString();
      startDate = iso;
      endDate = iso;
    }
  }
  return { dateRange: cleaned, startDate, endDate };
}

function categorizeRace(race = '') {
  const text = race.toLowerCase();
  if (text.includes('president') || text.includes('presidential')) return 'presidential';
  if (text.includes('senate')) return 'senate';
  if (text.includes('house')) return 'house';
  if (text.includes('governor')) return 'governor';
  if (text.includes('primary')) return 'primary';
  if (text.includes('approval')) return 'approval';
  if (text.includes('issue') || text.includes('%')) return 'issue';
  return 'general';
}

function buildPollInsight(poll) {
  if (!poll) return '';
  const base = `${poll.pollsterName} ${poll.race}`;
  if (poll.spread && poll.spread.margin !== null && poll.spread.margin !== undefined) {
    const direction = poll.spread.margin === 0 ? 'finds a dead heat' : poll.spread.margin > 0 ? 'shows' : 'shows';
    const leader = poll.spread.margin === 0 ? '' : `${poll.spread.leader} leading`;
    const marginText = poll.spread.margin === 0 ? '' : ` by ${Math.abs(poll.spread.margin)} points`;
    const population = poll.population || poll.sample || 'respondents';
    return `HalHar Insight AI ${direction} ${leader}${marginText} among ${population.toLowerCase()} in the latest survey.`.replace(/\s+/g, ' ').trim();
  }
  if (poll.results && poll.results.length) {
    const top = [...poll.results].sort((a, b) => (b.value || 0) - (a.value || 0))[0];
    if (top) {
      return `HalHar Insight AI highlights ${base}, where ${top.label} posts ${top.value}% support.`;
    }
  }
  return `HalHar Insight AI is monitoring ${base} for fresh data.`;
}

function buildPollDigest(polls = []) {
  if (!polls.length) {
    return {
      summary: 'HalHar Insight AI is monitoring trusted conservative pollsters for new survey releases. Check back soon for the latest numbers.',
      topIds: [],
      pollCount: 0,
      pollsterCount: 0
    };
  }
  const uniquePollsters = new Set(polls.map((poll) => poll.pollsterName));
  const sorted = [...polls].sort((a, b) => (b.sortTime || 0) - (a.sortTime || 0));
  const windowStart = sorted[sorted.length - 1]?.endDate || sorted[sorted.length - 1]?.startDate;
  const windowEnd = sorted[0]?.endDate || sorted[0]?.startDate;
  let rangeText = '';
  if (windowStart && windowEnd) {
    const start = new Date(windowStart);
    const end = new Date(windowEnd);
    if (!Number.isNaN(start.getTime()) && !Number.isNaN(end.getTime())) {
      const diffDays = Math.max(1, Math.round((end.getTime() - start.getTime()) / (1000 * 60 * 60 * 24)));
      rangeText = `over the past ${diffDays} day${diffDays === 1 ? '' : 's'}`;
    }
  }
  const highlightParts = [];
  for (const poll of sorted.slice(0, 3)) {
    if (poll.spread && poll.spread.margin !== null && poll.spread.margin !== undefined && poll.spread.leader) {
      highlightParts.push(`${poll.pollsterName} has ${poll.spread.leader} ${poll.spread.margin >= 0 ? 'up' : 'down'} ${Math.abs(poll.spread.margin)} in ${poll.race}`);
    } else if (poll.results && poll.results.length) {
      const top = [...poll.results].sort((a, b) => (b.value || 0) - (a.value || 0))[0];
      if (top) {
        highlightParts.push(`${poll.pollsterName} records ${top.label} at ${top.value}% in ${poll.race}`);
      }
    }
  }
  const summaryCore = `HalHar Insight AI analyzed ${polls.length} conservative-aligned polls from ${uniquePollsters.size} pollsters ${rangeText || 'in the latest update'}.`;
  const detail = highlightParts.length ? ` Key notes: ${highlightParts.join('; ')}.` : '';
  return {
    summary: `${summaryCore}${detail}`.replace(/\s+/g, ' ').trim(),
    topIds: sorted.slice(0, 8).map((poll) => poll.id),
    pollCount: polls.length,
    pollsterCount: uniquePollsters.size
  };
}

function parsePollRows(html) {
  const tableMatch = html.match(/<table[^>]*>([\s\S]*?)<\/table>/i);
  const tableHtml = tableMatch ? tableMatch[0] : html;
  const rowRegex = /<tr[^>]*>([\s\S]*?)<\/tr>/gi;
  const rows = [];
  let match;
  while ((match = rowRegex.exec(tableHtml)) !== null) {
    const rowHtml = match[1];
    if (/<th/i.test(rowHtml)) continue;
    const cells = [];
    const cellRegex = /<td[^>]*>([\s\S]*?)<\/td>/gi;
    let cellMatch;
    while ((cellMatch = cellRegex.exec(rowHtml)) !== null) {
      cells.push(cellMatch[1]);
    }
    if (cells.length >= 5) {
      rows.push(cells);
    }
  }
  return rows;
}

function buildPollFromCells(cells) {
  const raceHtml = cells[0];
  const pollsterHtml = cells[1];
  const dateHtml = cells[2];
  const sampleHtml = cells[3];
  const resultsHtml = cells[4];
  const spreadHtml = cells[5] || '';

  const pollsterMeta = resolvePollsterMeta(pollsterHtml);
  if (!pollsterMeta) {
    return null;
  }

  const race = sanitizeText(raceHtml);
  const link = resolveUrl(matchAttribute(raceHtml, 'a', 'href') || '', POLL_BASE_URL);
  const { dateRange, startDate, endDate } = parseDateRangeText(dateHtml);
  const { sampleSize, population } = parseSampleInfo(sampleHtml);
  const results = parseResultCell(resultsHtml);
  const spread = parseSpreadText(spreadHtml, results);

  const sortTimeSource = endDate || startDate;
  const sortTime = sortTimeSource ? new Date(sortTimeSource).getTime() : Date.now();

  const idBase = `${pollsterMeta.id}-${race.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
  const id = `${idBase}-${sortTime}`;

  return {
    id,
    pollsterId: pollsterMeta.id,
    pollsterName: pollsterMeta.name,
    category: pollsterMeta.category,
    race,
    topic: categorizeRace(race),
    link,
    dateRange,
    startDate,
    endDate,
    sampleSize,
    population,
    sample: sanitizeText(sampleHtml),
    results,
    resultText: sanitizeText(resultsHtml),
    spread,
    spreadText: sanitizeText(spreadHtml),
    insight: buildPollInsight({
      pollsterName: pollsterMeta.name,
      race,
      spread,
      population,
      sample: sanitizeText(sampleHtml),
      results
    }),
    sortTime: Number.isNaN(sortTime) ? Date.now() : sortTime
  };
}

async function fetchPollSourceHtml() {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);
  try {
    const response = await fetch(POLL_SOURCE_URL, {
      signal: controller.signal,
      headers: {
        'User-Agent': 'HalHarReportBot/1.0 (+https://halhar.report)',
        Accept: 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
      }
    });
    if (!response.ok) {
      throw new Error(`Poll source HTTP ${response.status}`);
    }
    const text = await response.text();
    if (!text || !text.trim()) {
      throw new Error('Empty poll response');
    }
    return text;
  } finally {
    clearTimeout(timeout);
  }
}

async function buildPollAggregate() {
  const html = await fetchPollSourceHtml();
  const rows = parsePollRows(html);
  const polls = [];
  for (const cells of rows) {
    const poll = buildPollFromCells(cells);
    if (poll) {
      polls.push(poll);
    }
  }
  polls.sort((a, b) => (b.sortTime || 0) - (a.sortTime || 0));
  const digest = buildPollDigest(polls);
  const pollsters = POLLSTER_METADATA.map((meta) => {
    const hasData = polls.some((poll) => poll.pollsterId === meta.id);
    return {
      id: meta.id,
      name: meta.name,
      category: meta.category,
      website: meta.website,
      favicon: meta.favicon,
      aliases: meta.aliases,
      hasData,
      unloaded: false,
      message: hasData ? null : 'No fresh polling captured in the latest refresh.'
    };
  });
  return {
    items: polls.slice(0, 240),
    pollsters,
    digest,
    source: POLL_SOURCE_URL
  };
}

async function fetchAndStorePolls(entry) {
  entry.lastAttempt = Date.now();
  const data = await buildPollAggregate();
  entry.data = data;
  entry.fetchedAt = Date.now();
  entry.lastSuccessAt = entry.fetchedAt;
  entry.failCount = 0;
  entry.lastError = null;
  entry.unloaded = false;
  entry.diskLoaded = true;
  await persistPollCache(entry);
}

async function getPollCacheEntry() {
  const now = Date.now();
  if (!pollCacheEntry.diskLoaded) {
    pollCacheEntry.diskLoaded = true;
    try {
      const cached = await loadPollCacheFromDisk();
      if (cached) {
        hydratePollEntryFromDisk(pollCacheEntry, cached);
      }
    } catch (error) {
      console.warn('Failed to hydrate poll cache', error.message);
    }
  }
  if (!pollCacheEntry.data) {
    try {
      await fetchAndStorePolls(pollCacheEntry);
    } catch (error) {
      pollCacheEntry.lastError = error;
      pollCacheEntry.failCount += 1;
      if (pollCacheEntry.failCount >= 3) {
        pollCacheEntry.unloaded = true;
      }
      throw error;
    }
  } else if (now - pollCacheEntry.fetchedAt > CACHE_TTL_MS && !pollCacheEntry.fetching) {
    pollCacheEntry.fetching = (async () => {
      try {
        await fetchAndStorePolls(pollCacheEntry);
      } catch (error) {
        pollCacheEntry.lastError = error;
        pollCacheEntry.failCount += 1;
        if (pollCacheEntry.failCount >= 3) {
          pollCacheEntry.unloaded = true;
        }
      } finally {
        pollCacheEntry.fetching = null;
      }
    })();
  }
  return pollCacheEntry;
}

function buildPollResponse(entry) {
  if (!entry.data) {
    return {
      items: [],
      pollsters: POLLSTER_METADATA.map((meta) => ({
        id: meta.id,
        name: meta.name,
        category: meta.category,
        website: meta.website,
        favicon: meta.favicon,
        aliases: meta.aliases,
        hasData: false,
        unloaded: true,
        message: entry.lastError ? entry.lastError.message : 'Poll cache not ready.'
      })),
      digest: buildPollDigest([]),
      fetchedAt: null,
      stale: false,
      error: true,
      unloaded: true,
      source: POLL_SOURCE_URL,
      message: entry.lastError ? entry.lastError.message : 'Poll cache not ready.'
    };
  }
  const stale = Date.now() - entry.fetchedAt > CACHE_TTL_MS;
  const mappedPollsters = entry.data.pollsters.map((pollster) => ({
    ...pollster,
    unloaded: pollster.unloaded || entry.unloaded,
    hasData: pollster.hasData && !entry.unloaded,
    message: entry.unloaded ? entry.lastError?.message || 'Poll feed unavailable.' : pollster.message
  }));
  return {
    ...entry.data,
    pollsters: mappedPollsters,
    fetchedAt: new Date(entry.fetchedAt).toISOString(),
    stale,
    error: entry.unloaded || false,
    unloaded: entry.unloaded || false,
    source: entry.data.source,
    message: entry.unloaded && entry.lastError ? entry.lastError.message : null
  };
}

async function handlePollRequest(res) {
  try {
    const entry = await getPollCacheEntry();
    const payload = buildPollResponse(entry);
    sendJson(res, 200, payload);
  } catch (error) {
    const payload = buildPollResponse(pollCacheEntry);
    payload.error = true;
    payload.message = error.message || 'Unable to refresh polls.';
    sendJson(res, 200, payload);
  }
}

function finalizeFeed(feed, sourceUrl) {
  const items = (feed.items || []).map((item) => ({
    ...item,
    description: item.description || '',
    summary: item.summary || '',
    author: item.author || '',
    image: item.image || '',
    published: item.published || item.updated || null,
    youtubeId: item.youtubeId || (item.link ? detectYouTubeId(item.link) : '')
  }));

  items.sort((a, b) => {
    const aTime = a.published ? new Date(a.published).getTime() : 0;
    const bTime = b.published ? new Date(b.published).getTime() : 0;
    return bTime - aTime;
  });

  return {
    title: feed.title || '',
    description: feed.description || '',
    link: feed.link || sourceUrl,
    items: items.slice(0, MAX_ITEMS),
    isYoutube: isYoutubeUrl(feed.link || sourceUrl) || items.some((item) => item.youtubeId)
  };
}

function normalizeRssItem(xml, sourceUrl, channelLink) {
  const title = sanitize(extractTag(xml, 'title')) || 'Untitled';
  const rawLink = sanitize(extractTag(xml, 'link'));
  const guid = sanitize(extractTag(xml, 'guid'));
  const link = resolveUrl(rawLink || guid || '', channelLink || sourceUrl);
  const author = sanitize(extractTag(xml, 'dc:creator')) || sanitize(extractTag(xml, 'author'));
  const contentHtml = extractTag(xml, 'content:encoded') || extractTag(xml, 'description') || '';
  const description = decodeCdata(contentHtml);
  const summary = stripTags(description);
  const categories = extractTags(xml, 'category').map((value) => sanitize(value)).filter(Boolean);
  const enclosureUrl = matchAttribute(xml, 'enclosure', 'url');
  const enclosureType = matchAttribute(xml, 'enclosure', 'type');
  let image = matchAttribute(xml, 'media[:\\w-]*thumbnail', 'url') || matchAttribute(xml, 'media[:\\w-]*content', 'url');
  if (!image && enclosureUrl && enclosureType && enclosureType.startsWith('image')) {
    image = enclosureUrl;
  }
  if (!image) {
    image = extractFirstImageFromHtml(description);
  }
  const published = parseDateValue(sanitize(extractTag(xml, 'pubDate')) || sanitize(extractTag(xml, 'published')) || sanitize(extractTag(xml, 'updated')) || sanitize(extractTag(xml, 'dc:date')));
  const updated = parseDateValue(sanitize(extractTag(xml, 'updated')) || sanitize(extractTag(xml, 'lastBuildDate')));
  const youtubeId = sanitize(extractTag(xml, 'yt:videoId')) || detectYouTubeId(link);

  return {
    id: guid || link || title,
    title,
    link,
    author,
    description,
    summary,
    published,
    updated,
    image,
    categories,
    youtubeId
  };
}

function normalizeRssFeed(xml, sourceUrl) {
  const channelMatch = xml.match(/<channel[^>]*>([\s\S]*?)<\/channel>/i);
  const channelBody = channelMatch ? channelMatch[1] : xml;
  const title = sanitize(extractTag(channelBody, 'title'));
  const description = sanitize(extractTag(channelBody, 'description'));
  const link = resolveUrl(sanitize(extractTag(channelBody, 'link')), sourceUrl);

  const items = [];
  const itemRegex = /<item\b[\s\S]*?<\/item>/gi;
  let match;
  while ((match = itemRegex.exec(xml)) !== null) {
    items.push(normalizeRssItem(match[0], sourceUrl, link));
  }

  return finalizeFeed({title, description, link, items}, sourceUrl);
}

function extractAtomLink(entryXml, sourceUrl) {
  const alternate = entryXml.match(/<link[^>]*rel=["']alternate["'][^>]*href=["']([^"']+)["'][^>]*\/?/i);
  if (alternate) {
    return resolveUrl(alternate[1], sourceUrl);
  }
  const generic = entryXml.match(/<link[^>]*href=["']([^"']+)["'][^>]*\/?/i);
  if (generic) {
    return resolveUrl(generic[1], sourceUrl);
  }
  const text = sanitize(extractTag(entryXml, 'link'));
  return resolveUrl(text, sourceUrl);
}

function normalizeAtomEntry(entryXml, sourceUrl) {
  const title = sanitize(extractTag(entryXml, 'title')) || 'Untitled';
  const link = extractAtomLink(entryXml, sourceUrl);
  const authorBlock = extractTag(entryXml, 'author');
  let author = '';
  if (authorBlock) {
    author = sanitize(extractTag(authorBlock, 'name')) || sanitize(authorBlock);
  }
  const summaryHtml = extractTag(entryXml, 'content') || extractTag(entryXml, 'summary') || '';
  const description = decodeCdata(summaryHtml);
  const summary = stripTags(description);
  const categories = extractTags(entryXml, 'category').map((value) => sanitize(value)).filter(Boolean);
  let image = matchAttribute(entryXml, 'media[:\\w-]*thumbnail', 'url') || matchAttribute(entryXml, 'media[:\\w-]*content', 'url');
  if (!image) {
    image = extractFirstImageFromHtml(description);
  }
  const published = parseDateValue(sanitize(extractTag(entryXml, 'published')) || sanitize(extractTag(entryXml, 'updated')));
  const updated = parseDateValue(sanitize(extractTag(entryXml, 'updated')));
  const youtubeId = sanitize(extractTag(entryXml, 'yt:videoId')) || detectYouTubeId(link);

  return {
    id: sanitize(extractTag(entryXml, 'id')) || link || title,
    title,
    link,
    author,
    description,
    summary,
    published,
    updated,
    image,
    categories,
    youtubeId
  };
}

function normalizeAtomFeed(xml, sourceUrl) {
  const feedMatch = xml.match(/<feed[^>]*>([\s\S]*?)<\/feed>/i);
  const feedBody = feedMatch ? feedMatch[1] : xml;
  const title = sanitize(extractTag(feedBody, 'title'));
  const description = sanitize(extractTag(feedBody, 'subtitle'));
  const link = extractAtomLink(feedBody, sourceUrl);

  const entries = [];
  const entryRegex = /<entry\b[\s\S]*?<\/entry>/gi;
  let match;
  while ((match = entryRegex.exec(xml)) !== null) {
    entries.push(normalizeAtomEntry(match[0], sourceUrl));
  }

  return finalizeFeed({title, description, link, items: entries}, sourceUrl);
}

function normalizeJsonFeed(json, sourceUrl) {
  const items = (json.items || json.entries || []).map((entry, index) => {
    const link = resolveUrl(entry.url || entry.link || '', sourceUrl);
    const image = entry.image || entry.thumbnail || (entry.enclosure && (entry.enclosure.url || entry.enclosure.link)) || (Array.isArray(entry.enclosures) && entry.enclosures[0] ? entry.enclosures[0].url : '') || extractFirstImageFromHtml(entry.content_html || entry.summary || entry.description || '');
    const published = parseDateValue(entry.date_published || entry.published || entry.pubDate || entry.updated);
    const updated = parseDateValue(entry.updated || entry.date_modified);
    const author = entry.author ? (entry.author.name || entry.author) : '';
    const description = entry.content_html || entry.summary || entry.description || entry.content_text || '';
    return {
      id: entry.id || link || `${index}`,
      title: entry.title || 'Untitled',
      link,
      author,
      description,
      summary: entry.summary || stripTags(description),
      published,
      updated,
      image,
      categories: entry.tags || entry.categories || [],
      youtubeId: detectYouTubeId(link)
    };
  });

  return finalizeFeed({
    title: json.title || json.name || '',
    description: json.description || json.subtitle || '',
    link: resolveUrl(json.home_page_url || json.feed_url || sourceUrl, sourceUrl),
    items
  }, sourceUrl);
}

function normalizeFeedFromText(raw, sourceUrl) {
  const trimmed = raw.trim();
  if (!trimmed) {
    throw new Error('Empty feed response');
  }
  if (trimmed.startsWith('{') || trimmed.startsWith('[')) {
    const json = JSON.parse(trimmed);
    return normalizeJsonFeed(json, sourceUrl);
  }
  if (/<feed[\s>]/i.test(trimmed) && /<entry[\s>]/i.test(trimmed)) {
    return normalizeAtomFeed(trimmed, sourceUrl);
  }
  return normalizeRssFeed(trimmed, sourceUrl);
}

async function fetchAndNormalize(url) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);
  try {
    const response = await fetch(url, {
      signal: controller.signal,
      headers: {
        'User-Agent': 'HalHarReportBot/1.0 (+https://halhar.report)',
        Accept: 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8, application/json;q=0.8, text/html;q=0.7'
      }
    });
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }
    const buffer = await response.arrayBuffer();
    const contentType = response.headers.get('content-type') || '';
    const charsetMatch = contentType.match(/charset=([^;]+)/i);
    const charset = charsetMatch ? charsetMatch[1].trim().toLowerCase() : 'utf-8';
    let text;
    try {
      text = new TextDecoder(charset).decode(buffer);
    } catch (error) {
      text = new TextDecoder('utf-8').decode(buffer);
    }
    const normalized = normalizeFeedFromText(text, url);
    return normalized;
  } finally {
    clearTimeout(timeout);
  }
}

function ensureEntry(url) {
  let entry = cache.get(url);
  if (!entry) {
    entry = {
      data: null,
      fetchedAt: 0,
      lastSuccessAt: 0,
      failCount: 0,
      lastError: null,
      fetching: null,
      unloaded: false,
      lastAttempt: 0,
      diskLoaded: false
    };
    cache.set(url, entry);
  }
  return entry;
}

async function fetchAndStore(url, entry) {
  entry.lastAttempt = Date.now();
  const normalized = await fetchAndNormalize(url);
  entry.data = {
    ...normalized,
    url
  };
  entry.fetchedAt = Date.now();
  entry.lastSuccessAt = entry.fetchedAt;
  entry.failCount = 0;
  entry.unloaded = false;
  entry.lastError = null;
  entry.diskLoaded = true;
  await persistFeedCache(url, entry);
}

async function getFeedResponse(url) {
  const normalizedUrl = normalizeUrl(url);
  const entry = ensureEntry(normalizedUrl);
  const now = Date.now();

  if (!entry.diskLoaded) {
    entry.diskLoaded = true;
    try {
      const cached = await loadFeedCacheFromDisk(normalizedUrl);
      if (cached) {
        hydrateFeedEntryFromDisk(entry, cached);
      }
    } catch (error) {
      console.warn('Failed to hydrate feed cache for', normalizedUrl, error.message);
    }
  }

  if (!entry.data) {
    try {
      await fetchAndStore(normalizedUrl, entry);
    } catch (error) {
      entry.lastError = error;
      entry.failCount += 1;
      if (entry.failCount >= 3) {
        entry.unloaded = true;
      }
      throw error;
    }
  } else if (now - entry.fetchedAt > CACHE_TTL_MS) {
    if (!entry.fetching) {
      entry.fetching = (async () => {
        try {
          await fetchAndStore(normalizedUrl, entry);
        } catch (error) {
          entry.lastError = error;
          entry.failCount += 1;
          if (entry.failCount >= 3) {
            entry.unloaded = true;
          }
        } finally {
          entry.fetching = null;
        }
      })();
    }
  }

  return { normalizedUrl, entry };
}

function buildResponsePayload(url, entry) {
  if (!entry.data) {
    return {
      url,
      title: '',
      description: '',
      link: '',
      items: [],
      fetchedAt: entry.fetchedAt ? new Date(entry.fetchedAt).toISOString() : null,
      stale: false,
      error: true,
      unloaded: entry.unloaded,
      message: entry.lastError ? entry.lastError.message : 'Feed has not been cached yet.'
    };
  }
  const stale = Date.now() - entry.fetchedAt > CACHE_TTL_MS;
  return {
    ...entry.data,
    url,
    fetchedAt: new Date(entry.fetchedAt).toISOString(),
    stale,
    error: entry.unloaded || false,
    unloaded: entry.unloaded || false,
    message: entry.unloaded && entry.lastError ? entry.lastError.message : null
  };
}

function sendJson(res, statusCode, payload) {
  const body = JSON.stringify(payload);
  res.writeHead(statusCode, {
    'Content-Type': 'application/json; charset=utf-8',
    'Cache-Control': 'no-store',
    'X-Content-Type-Options': 'nosniff',
    'Cross-Origin-Resource-Policy': 'same-origin'
  });
  res.end(body);
}

async function handleApiRequest(req, res, reqUrl) {
  const feedUrl = reqUrl.searchParams.get('url');
  if (!feedUrl) {
    sendJson(res, 400, { error: true, message: 'Missing url parameter', items: [] });
    return;
  }
  const normalizedUrl = normalizeUrl(feedUrl);
  if (!ALLOWED_FEED_URLS.has(normalizedUrl)) {
    sendJson(res, 403, { error: true, message: 'Feed is not permitted', items: [] });
    return;
  }
  const entry = ensureEntry(normalizedUrl);
  try {
    const { entry: currentEntry } = await getFeedResponse(feedUrl);
    const payload = buildResponsePayload(normalizedUrl, currentEntry);
    sendJson(res, 200, payload);
  } catch (error) {
    const payload = buildResponsePayload(normalizedUrl, entry);
    payload.error = true;
    payload.message = error.message || 'Unable to refresh this feed';
    sendJson(res, 200, payload);
  }
}

async function serveStatic(req, res, pathname) {
  try {
    const decodedPath = decodeURIComponent(pathname || '/');
    let filePath = decodedPath === '/' ? 'halhar-report.html' : decodedPath.replace(/^\/+/, '');
    filePath = filePath.split('?')[0].split('#')[0];
    const normalized = path.posix.normalize(`/${filePath}`).replace(/^\/+/, '');
    const absolutePath = path.join(baseDir, normalized);
    if (!absolutePath.startsWith(baseDir)) {
      res.writeHead(403, {
        'Content-Type': 'text/plain; charset=utf-8',
        'X-Content-Type-Options': 'nosniff',
        'Cross-Origin-Resource-Policy': 'same-origin'
      });
      res.end('Forbidden');
      return;
    }

    const stats = await fsp.stat(absolutePath);
    if (stats.isDirectory()) {
      await serveStatic(req, res, path.join(pathname, 'index.html'));
      return;
    }
    const ext = path.extname(absolutePath).toLowerCase();
    const type = MIME_TYPES[ext] || 'application/octet-stream';
    const stream = fs.createReadStream(absolutePath);
    res.writeHead(200, {
      'Content-Type': type,
      'Cache-Control': ext === '.html' ? 'no-store' : 'public, max-age=300',
      'X-Content-Type-Options': 'nosniff',
      'Cross-Origin-Resource-Policy': 'same-origin',
      'Referrer-Policy': 'no-referrer-when-downgrade'
    });
    stream.pipe(res);
    stream.on('error', () => {
      res.writeHead(500, {
        'Content-Type': 'text/plain; charset=utf-8',
        'X-Content-Type-Options': 'nosniff'
      });
      res.end('Internal Server Error');
    });
  } catch (error) {
    res.writeHead(404, {
      'Content-Type': 'text/plain; charset=utf-8',
      'X-Content-Type-Options': 'nosniff'
    });
    res.end('Not Found');
  }
}

const server = http.createServer(async (req, res) => {
  try {
    const reqUrl = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
    if (req.method === 'GET' && reqUrl.pathname === '/api/fetch-feed') {
      await handleApiRequest(req, res, reqUrl);
      return;
    }
    if (req.method === 'GET' && reqUrl.pathname === '/api/polls') {
      await handlePollRequest(res);
      return;
    }
    if (req.method === 'GET') {
      await serveStatic(req, res, reqUrl.pathname);
      return;
    }
    res.writeHead(405, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('Method Not Allowed');
  } catch (error) {
    res.writeHead(500, {
      'Content-Type': 'text/plain; charset=utf-8',
      'X-Content-Type-Options': 'nosniff'
    });
    res.end('Internal Server Error');
  }
});

server.listen(PORT, () => {
  console.log(`HalHar server listening on http://localhost:${PORT}`);
});
