<?php
// detector.php
// 민감정보 탐지기 — 1단계
// PHP는 HTML 출력 전용. 모든 파싱/탐지 로직은 브라우저 JS에서 실행.
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>민감정보 탐지기 — 1단계</title>

  <!-- JSZip: ZIP 기반 파일(HWPX·DOCX·PPTX·XLSX) 파싱 -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
  <!-- PDF.js: PDF 파싱 -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.2.67/pdf.min.mjs" type="module" id="pdfjs-script"></script>

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --navy:   #1B2E5E;
      --blue:   #2563EB;
      --bg:     #F8FAFC;
      --white:  #ffffff;
      --border: #E2E8F0;
      --text:   #0F172A;
      --muted:  #64748B;
      --red-bg: #FEE2E2; --red-border: #EF4444; --red-text: #B91C1C; --red-badge: #DC2626;
      --ylw-bg: #FEF3C7; --ylw-border: #F59E0B; --ylw-text: #92400E; --ylw-badge: #D97706;
    }

    body {
      font-family: 'Pretendard', 'Apple SD Gothic Neo', 'Noto Sans KR', sans-serif;
      min-height: 100vh;
      background: var(--bg);
      color: var(--text);
    }

    /* ── 헤더 ── */
    .header {
      background: var(--navy);
      color: #fff;
      padding: 20px 28px;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .header-icon {
      width: 36px; height: 36px;
      background: var(--blue);
      border-radius: 8px;
      display: flex; align-items: center; justify-content: center;
      font-size: 18px; flex-shrink: 0;
    }
    .header-title { font-weight: 700; font-size: 16px; }
    .header-sub   { font-size: 12px; color: #94A3B8; margin-top: 2px; }
    .header-badge {
      margin-left: auto;
      font-size: 12px; color: #64748B;
      background: #0F172A;
      border-radius: 6px; padding: 4px 10px;
      white-space: nowrap;
    }

    /* ── 본문 ── */
    .container { max-width: 900px; margin: 0 auto; padding: 28px 20px; }

    /* ── 업로드 존 ── */
    .drop-zone {
      border: 2px dashed var(--border);
      border-radius: 16px;
      padding: 40px 24px;
      text-align: center;
      cursor: pointer;
      background: var(--white);
      transition: border-color 0.2s;
      margin-bottom: 24px;
      user-select: none;
    }
    .drop-zone:hover, .drop-zone.dragover { border-color: var(--blue); }
    .drop-zone .icon  { font-size: 36px; margin-bottom: 12px; }
    .drop-zone .main  { font-weight: 600; font-size: 15px; color: #334155; }
    .drop-zone .sub   { font-size: 13px; color: #94A3B8; margin-top: 6px; }
    .drop-zone .fname { font-weight: 600; color: var(--navy); }
    .drop-zone .fmeta { font-size: 13px; color: var(--muted); margin-top: 4px; }

    /* ── 로딩 ── */
    #loading { display: none; text-align: center; padding: 40px 0; color: var(--muted); }
    #loading .spin { font-size: 28px; margin-bottom: 12px; animation: spin 1.2s linear infinite; display: inline-block; }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* ── 에러 ── */
    #error-box {
      display: none;
      background: var(--red-bg);
      border: 1px solid var(--red-border);
      border-radius: 10px;
      padding: 14px 18px;
      color: var(--red-text);
      margin-bottom: 16px;
    }

    /* ── 결과 영역 ── */
    #results { display: none; }

    /* 요약 카드 */
    .summary-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 14px;
      margin-bottom: 24px;
    }
    .summary-card { border-radius: 12px; padding: 18px 20px; }
    .summary-card .s-label { font-size: 13px; font-weight: 600; }
    .summary-card .s-count { font-size: 32px; font-weight: 800; margin-top: 6px; }
    .summary-card .s-unit  { font-size: 12px; opacity: 0.7; }

    /* 빈 결과 */
    .empty-result {
      background: #F0FDF4;
      border: 1px solid #86EFAC;
      border-radius: 12px;
      padding: 28px;
      text-align: center;
      color: #166534;
    }
    .empty-result .e-icon  { font-size: 28px; margin-bottom: 8px; }
    .empty-result .e-main  { font-weight: 600; }
    .empty-result .e-sub   { font-size: 13px; margin-top: 6px; color: #4ADE80; }

    /* ── 필터 탭 ── */
    .filter-tabs { display: flex; gap: 8px; margin-bottom: 18px; flex-wrap: wrap; }
    .filter-btn {
      padding: 6px 16px;
      border-radius: 20px;
      border: none;
      cursor: pointer;
      font-size: 13px;
      background: #E2E8F0;
      color: #475569;
      font-weight: 400;
      transition: all 0.15s;
      font-family: inherit;
    }
    .filter-btn.active-all { background: var(--navy); color: #fff; font-weight: 700; }
    .filter-btn.active-c   { background: #DC2626;    color: #fff; font-weight: 700; }
    .filter-btn.active-s   { background: #D97706;    color: #fff; font-weight: 700; }

    /* ── 그룹 카드 ── */
    .group-card {
      background: var(--white);
      border-radius: 12px;
      border: 1px solid var(--border);
      margin-bottom: 12px;
      overflow: hidden;
      transition: border-color 0.2s;
    }
    .group-header {
      display: flex;
      align-items: center;
      padding: 14px 18px;
      cursor: pointer;
      transition: background 0.2s;
      gap: 10px;
    }
    .badge-grade {
      border-radius: 6px; padding: 2px 8px;
      font-size: 12px; font-weight: 700; flex-shrink: 0;
      color: #fff;
    }
    .group-name  { font-weight: 700; font-size: 15px; color: #0F172A; flex: 1; }
    .badge-action {
      border-radius: 6px; padding: 2px 10px;
      font-size: 12px; font-weight: 600; flex-shrink: 0;
    }
    .badge-count {
      background: #F1F5F9; color: #475569;
      border-radius: 20px; padding: 2px 10px;
      font-size: 13px; font-weight: 700; flex-shrink: 0;
    }
    .chevron { color: #94A3B8; font-size: 14px; flex-shrink: 0; }

    /* 그룹 바디 */
    .group-body { padding: 0 18px; }
    .group-desc {
      font-size: 13px;
      border-radius: 8px;
      padding: 8px 12px;
      margin-bottom: 12px;
    }

    /* 탐지 테이블 */
    .detect-table {
      width: 100%; border-collapse: collapse;
      font-size: 13px; margin-bottom: 14px;
    }
    .detect-table th {
      padding: 8px 10px; text-align: left;
      color: #64748B; font-weight: 600;
      border-bottom: 1px solid var(--border);
      background: #F8FAFC;
    }
    .detect-table th:nth-child(1) { width: 160px; }
    .detect-table th:nth-child(2) { width: 120px; }
    .detect-table td {
      padding: 8px 10px;
      border-bottom: 1px solid #F1F5F9;
      vertical-align: top;
      line-height: 1.6;
    }
    .td-matched { font-family: monospace; font-weight: 700; }
    .badge-loc {
      background: #EEF2FF; color: #3730A3;
      border-radius: 5px; padding: 2px 7px;
      font-size: 12px; font-weight: 600;
      white-space: nowrap; display: inline-block;
    }
    .td-ctx { color: #475569; }
    mark.hit {
      border-radius: 3px; padding: 0 2px;
      font-style: normal;
    }

    /* 주의사항 */
    .notice {
      margin-top: 20px;
      background: #F8FAFC;
      border: 1px solid #CBD5E1;
      border-radius: 12px;
      padding: 14px 18px;
      font-size: 13px; color: #64748B;
      line-height: 1.8;
    }
    .notice strong { color: #334155; }

    @media (max-width: 600px) {
      .summary-grid { grid-template-columns: 1fr; }
      .header { padding: 16px; }
      .container { padding: 16px 12px; }
    }
  </style>
</head>
<body>

<!-- 헤더 -->
<div class="header">
  <div class="header-icon">🔍</div>
  <div>
    <div class="header-title">민감정보 탐지기 — 1단계</div>
    <div class="header-sub">HWPX · DOCX · PPTX · XLSX · PDF 지원</div>
  </div>
  <div class="header-badge">패턴(정규식) 기반</div>
</div>

<div class="container">

  <!-- 업로드 존 -->
  <div class="drop-zone" id="drop-zone">
    <input type="file" id="file-input" accept=".hwpx,.docx,.pptx,.xlsx,.pdf" style="display:none" />
    <div class="icon">📄</div>
    <div id="drop-label">
      <div class="main">파일을 드래그하거나 클릭하여 선택</div>
      <div class="sub">HWPX · DOCX · PPTX · XLSX · PDF</div>
    </div>
  </div>

  <!-- 로딩 -->
  <div id="loading">
    <div class="spin">⏳</div>
    <div>파일 분석 중...</div>
  </div>

  <!-- 에러 -->
  <div id="error-box"></div>

  <!-- 결과 -->
  <div id="results"></div>

</div>

<script type="module">
// ─────────────────────────────────────────────────────────────
// PDF.js worker 설정
// ─────────────────────────────────────────────────────────────
import * as pdfjsLib from 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.2.67/pdf.min.mjs';
pdfjsLib.GlobalWorkerOptions.workerSrc =
  'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.2.67/pdf.worker.min.mjs';

// ─────────────────────────────────────────────────────────────
// 정규식 패턴
// ─────────────────────────────────────────────────────────────
const PATTERNS = [
  { id:"rrn",            label:"주민등록번호",      grade:"C", action:"삭제",  regex:/\b\d{6}[-–]\d{7}\b/g,                                                                                                                                              desc:"고유식별정보 — 어떠한 형태로도 외부 AI 입력 불가" },
  { id:"passport",       label:"여권번호",          grade:"C", action:"삭제",  regex:/\b[A-Z]{1,2}\d{7,9}\b/g,                                                                                                                                           desc:"고유식별정보" },
  { id:"ip",             label:"내부 IP 주소",      grade:"C", action:"삭제",  regex:/\b(10\.\d{1,3}\.\d{1,3}\.\d{1,3}|172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}|192\.168\.\d{1,3}\.\d{1,3})\b/g,                                                     desc:"내부 네트워크 정보 — 사이버 공격 악용 위험" },
  { id:"phone",          label:"전화번호",          grade:"S", action:"마스킹", regex:/\b0\d{1,2}[-–·]?\d{3,4}[-–·]?\d{4}\b/g,                                                                                                                           desc:"직접 식별 가능한 개인정보" },
  { id:"email",          label:"이메일 주소",        grade:"S", action:"마스킹", regex:/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/g,                                                                                                           desc:"직접 식별 가능한 개인정보" },
  { id:"empid",          label:"사번",              grade:"S", action:"마스킹", regex:/\b[A-Za-z]{2}\d{6}\b/g,                                                                                                                                            desc:"직접 식별 가능한 개인정보" },
  { id:"account",        label:"계좌번호",          grade:"S", action:"마스킹", regex:/\b(?:\d{3}-\d{2}-\d{4}-\d{3}|\d{4}-\d{3}-\d{6}|\d{3}-\d{6}-\d{5}|\d{3}-\d{2}-\d{6}|\d{3}-\d{6}-\d{2}-\d{3}|\d{3}-\d{4}-\d{4}-\d{2}|\d{4}-\d{4}-\d{4}-\d{1}|\d{6}-\d{2}-\d{6}|\d{4}-\d{2}-\d{7}|\d{3}-\d{6}-\d{3}|\d{2}-\d{2}-\d{6}|\d{3}-\d{2}-\d{6}-\d{1}|\d{3}-\d{4}-\d{4}-\d{3})\b|\b\d{10,14}\b/g, desc:"계좌번호 (국민·우리·하나·신한 등 / 하이픈 없는 10~14자리 포함)" },
  { id:"freq",           label:"무선 주파수",        grade:"S", action:"치환",  regex:/\b\d{2,3}\.\d{3,4}\s*[MmGg][Hh][Zz]\b/g,                                                                                                                           desc:"통신 보안 정보 — 도청·혼신 위험" },
  { id:"coord",          label:"정밀 위치(키로정)",  grade:"S", action:"범주화", regex:/\b\d{1,3}[Kk]\d{3}\b/g,                                                                                                                                            desc:"정밀 위치정보 — 범위(예: 37K~38K)로 범주화 권장" },
  { id:"datetime_precise",label:"정밀 시각",        grade:"S", action:"범주화", regex:/\b([01]\d|2[0-3]):[0-5]\d:[0-5]\d\b/g,                                                                                                                             desc:"정밀 시각 — 오전/오후 등 범주화 권장" },
  { id:"name_ko",        label:"한국인 성명 추정",   grade:"S", action:"마스킹", regex:/[가-힣]{2,4}(?:\s*(?:씨|님|부장|차장|과장|대리|주임|사원|팀장|본부장|이사|상무|전무|사장|기관사|기장|승무원))/g,                                                   desc:"직책·호칭과 결합된 성명 — 직접 식별 가능" },
  { id:"vlan",           label:"VLAN/포트 정보",    grade:"C", action:"삭제",  regex:/\bVLAN\s*\d+\b|\bport\s*\d{2,5}\b/gi,                                                                                                                              desc:"내부 네트워크 구성 정보" },
];

const GRADE_COLOR = {
  C: { bg:"#FEE2E2", border:"#EF4444", text:"#B91C1C", badge:"#DC2626" },
  S: { bg:"#FEF3C7", border:"#F59E0B", text:"#92400E", badge:"#D97706" },
};
const ACTION_COLOR = { 삭제:"#DC2626", 마스킹:"#7C3AED", 치환:"#2563EB", 범주화:"#059669" };

// ─────────────────────────────────────────────────────────────
// 파일 파서
// ─────────────────────────────────────────────────────────────
async function extractChunks(file) {
  const name = file.name.toLowerCase();
  if (name.endsWith(".hwpx")) return parseHwpx(file);
  if (name.endsWith(".docx")) return parseDocx(file);
  if (name.endsWith(".pptx")) return parsePptx(file);
  if (name.endsWith(".xlsx")) return parseXlsx(file);
  if (name.endsWith(".pdf"))  return parsePdf(file);
  throw new Error("지원하지 않는 파일 형식입니다.");
}

async function readZip(file) {
  const buf = await file.arrayBuffer();
  return JSZip.loadAsync(buf);
}

async function parseDocx(file) {
  const zip = await readZip(file);
  if (!zip.files["word/document.xml"]) throw new Error("word/document.xml 없음");
  const xml = await zip.files["word/document.xml"].async("string");
  const paraMatches = [...xml.matchAll(/<w:p[ >][\s\S]*?<\/w:p>/g)];
  const chunks = []; let paraNum = 0;
  for (const m of paraMatches) {
    const ts = [...m[0].matchAll(/<w:t[^>]*>(.*?)<\/w:t>/g)].map(t => t[1]);
    const text = ts.join("").trim();
    if (text) { paraNum++; chunks.push({ text, location: `${paraNum}번째 단락` }); }
  }
  return chunks;
}

async function parsePptx(file) {
  const zip = await readZip(file);
  const slides = Object.keys(zip.files)
    .filter(k => k.startsWith("ppt/slides/slide") && k.endsWith(".xml"))
    .sort((a,b) => parseInt(a.match(/slide(\d+)/)?.[1]||0) - parseInt(b.match(/slide(\d+)/)?.[1]||0));
  const chunks = [];
  for (let i = 0; i < slides.length; i++) {
    const xml = await zip.files[slides[i]].async("string");
    const paraMatches = [...xml.matchAll(/<a:p[ >][\s\S]*?<\/a:p>/g)];
    let paraNum = 0;
    for (const m of paraMatches) {
      const ts = [...m[0].matchAll(/<a:t[^>]*>(.*?)<\/a:t>/g)].map(t => t[1]);
      const text = ts.join("").trim();
      if (text) { paraNum++; chunks.push({ text, location: `슬라이드 ${i+1} · ${paraNum}번째 단락` }); }
    }
  }
  return chunks;
}

async function parseHwpx(file) {
  const zip = await readZip(file);
  const sections = Object.keys(zip.files)
    .filter(k => k.startsWith("Contents/section") && k.endsWith(".xml")).sort();
  const chunks = []; let paraNum = 0;
  for (const secFile of sections) {
    const xml = await zip.files[secFile].async("string");
    const paraMatches = [...xml.matchAll(/<hp:p[ >][\s\S]*?<\/hp:p>/g)];
    for (const m of paraMatches) {
      const ts = [...m[0].matchAll(/<hp:t\b[^>]*>([^<]*)<\/hp:t>/g)].map(t => t[1]);
      const text = ts.join("").trim();
      if (text) { paraNum++; chunks.push({ text, location: `${paraNum}번째 단락` }); }
    }
  }
  return chunks;
}

async function parseXlsx(file) {
  const zip = await readZip(file);
  const sheetNames = {};
  if (zip.files["xl/workbook.xml"]) {
    const wbXml = await zip.files["xl/workbook.xml"].async("string");
    [...wbXml.matchAll(/sheetId="(\d+)"[^>]*name="([^"]+)"/g)]
      .forEach(m => { sheetNames[m[1]] = m[2]; });
  }
  let shared = [];
  if (zip.files["xl/sharedStrings.xml"]) {
    const xml = await zip.files["xl/sharedStrings.xml"].async("string");
    shared = [...xml.matchAll(/<t[^>]*>([^<]*)<\/t>/g)].map(m => m[1]);
  }
  const sheets = Object.keys(zip.files)
    .filter(k => k.startsWith("xl/worksheets/sheet") && k.endsWith(".xml")).sort();
  const chunks = [];
  for (let i = 0; i < sheets.length; i++) {
    const sheetNum = sheets[i].match(/sheet(\d+)/)?.[1] || String(i+1);
    const sheetName = sheetNames[sheetNum] || `Sheet${sheetNum}`;
    const xml = await zip.files[sheets[i]].async("string");
    const cellTexts = [];
    [...xml.matchAll(/<c[^>]*t="s"[^>]*><v>(\d+)<\/v>/g)]
      .forEach(m => { const idx = parseInt(m[1]); if (shared[idx]) cellTexts.push(shared[idx]); });
    [...xml.matchAll(/<t[^>]*>([^<]+)<\/t>/g)]
      .forEach(m => { if (m[1].trim()) cellTexts.push(m[1]); });
    const text = cellTexts.join(" ").trim();
    if (text) chunks.push({ text, location: sheetName });
  }
  const sharedText = shared.join(" ").trim();
  if (sharedText) chunks.push({ text: sharedText, location: "공유 문자열" });
  return chunks;
}

async function parsePdf(file) {
  const buf = await file.arrayBuffer();
  const pdf = await pdfjsLib.getDocument({ data: buf }).promise;
  const chunks = [];
  for (let i = 1; i <= pdf.numPages; i++) {
    const page = await pdf.getPage(i);
    const content = await page.getTextContent();
    const lines = {};
    for (const item of content.items) {
      if (!item.str.trim()) continue;
      const y = Math.round(item.transform[5]);
      if (!lines[y]) lines[y] = [];
      lines[y].push(item.str);
    }
    Object.keys(lines).sort((a,b) => b-a)
      .map(y => lines[y].join("").trim()).filter(t => t)
      .forEach((text, idx) => { chunks.push({ text, location: `${i}페이지 · ${idx+1}번째 줄` }); });
  }
  return chunks;
}

// ─────────────────────────────────────────────────────────────
// 탐지 엔진
// ─────────────────────────────────────────────────────────────
function detect(chunks) {
  const results = [];
  for (const { text, location } of chunks) {
    for (const p of PATTERNS) {
      const regex = new RegExp(p.regex.source, p.regex.flags);
      for (const m of [...text.matchAll(regex)]) {
        const start = Math.max(0, m.index - 40);
        const end   = Math.min(text.length, m.index + m[0].length + 40);
        const context = text.slice(start, end).replace(/\s+/g," ").trim();
        results.push({ patternId:p.id, label:p.label, grade:p.grade,
          action:p.action, desc:p.desc, matched:m[0], context, location });
      }
    }
  }
  return results;
}

// ─────────────────────────────────────────────────────────────
// DOM 헬퍼
// ─────────────────────────────────────────────────────────────
function esc(str) {
  return String(str)
    .replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;")
    .replace(/"/g,"&quot;");
}

function highlightContext(context, matched) {
  const parts = context.split(matched);
  return parts.map((p,i) =>
    i < parts.length-1
      ? `${esc(p)}<mark class="hit">${esc(matched)}</mark>`
      : esc(p)
  ).join("");
}

// ─────────────────────────────────────────────────────────────
// 렌더링
// ─────────────────────────────────────────────────────────────
let allResults  = [];
let currentFilter = "ALL";
let expandedId  = null;

function render() {
  const filtered = currentFilter === "ALL"
    ? allResults
    : allResults.filter(r => r.grade === currentFilter);

  const countAll = allResults.length;
  const countC   = allResults.filter(r => r.grade === "C").length;
  const countS   = allResults.filter(r => r.grade === "S").length;

  // 패턴별 그룹
  const grouped = filtered.reduce((acc, r) => {
    if (!acc[r.patternId]) acc[r.patternId] = {
      label:r.label, grade:r.grade, action:r.action, desc:r.desc, items:[]
    };
    acc[r.patternId].items.push(r);
    return acc;
  }, {});

  let html = `
    <!-- 요약 카드 -->
    <div class="summary-grid">
      <div class="summary-card" style="background:#EEF2FF">
        <div class="s-label" style="color:#1B2E5E">📋 전체 탐지</div>
        <div class="s-count" style="color:#1B2E5E">${countAll}</div>
        <div class="s-unit"  style="color:#1B2E5E">건 발견</div>
      </div>
      <div class="summary-card" style="background:#FEE2E2">
        <div class="s-label" style="color:#B91C1C">🔴 C급 (기밀)</div>
        <div class="s-count" style="color:#B91C1C">${countC}</div>
        <div class="s-unit"  style="color:#B91C1C">건 발견</div>
      </div>
      <div class="summary-card" style="background:#FEF3C7">
        <div class="s-label" style="color:#92400E">🟡 S급 (민감)</div>
        <div class="s-count" style="color:#92400E">${countS}</div>
        <div class="s-unit"  style="color:#92400E">건 발견</div>
      </div>
    </div>`;

  if (filtered.length === 0 && countAll === 0) {
    html += `
      <div class="empty-result">
        <div class="e-icon">✅</div>
        <div class="e-main">패턴 기반 탐지 결과 민감정보가 발견되지 않았습니다.</div>
        <div class="e-sub">단, 문맥 판단이 필요한 항목은 직접 검토하시기 바랍니다.</div>
      </div>`;
  } else {
    // 필터 탭
    const btnAll = `filter-btn${currentFilter==="ALL"?" active-all":""}`;
    const btnC   = `filter-btn${currentFilter==="C"  ?" active-c"  :""}`;
    const btnS   = `filter-btn${currentFilter==="S"  ?" active-s"  :""}`;
    html += `
      <div class="filter-tabs">
        <button class="${btnAll}" data-filter="ALL">전체 (${countAll})</button>
        <button class="${btnC}"   data-filter="C">C급 (${countC})</button>
        <button class="${btnS}"   data-filter="S">S급 (${countS})</button>
      </div>`;

    // 그룹 카드
    for (const [key, group] of Object.entries(grouped)) {
      const gc  = GRADE_COLOR[group.grade];
      const ac  = ACTION_COLOR[group.action] || "#666";
      const isOpen = expandedId === key;
      const borderStyle = isOpen ? `border-color:${gc.border}` : "";

      html += `
        <div class="group-card" style="${borderStyle}" data-group="${esc(key)}">
          <div class="group-header" style="background:${isOpen ? gc.bg : "#fff"}">
            <span class="badge-grade" style="background:${gc.badge}">${esc(group.grade)}급</span>
            <span class="group-name">${esc(group.label)}</span>
            <span class="badge-action" style="background:${ac}18;color:${ac};border:1px solid ${ac}40">${esc(group.action)} 권장</span>
            <span class="badge-count">${group.items.length}건</span>
            <span class="chevron">${isOpen ? "▲" : "▼"}</span>
          </div>`;

      if (isOpen) {
        html += `
          <div class="group-body">
            <div class="group-desc" style="color:${gc.text};background:${gc.bg}">ℹ️ ${esc(group.desc)}</div>
            <table class="detect-table">
              <thead>
                <tr>
                  <th>탐지 값</th>
                  <th>위치</th>
                  <th>전후 문맥</th>
                </tr>
              </thead>
              <tbody>`;
        for (const item of group.items) {
          html += `
                <tr>
                  <td class="td-matched" style="color:${gc.badge}">${esc(item.matched)}</td>
                  <td><span class="badge-loc">${esc(item.location)}</span></td>
                  <td class="td-ctx">${highlightContext(item.context, item.matched)}</td>
                </tr>`;
        }
        html += `
              </tbody>
            </table>
          </div>`;
      }
      html += `</div>`;
    }

    // 주의사항
    html += `
      <div class="notice">
        <strong>⚠️ 주의사항</strong><br>
        · 위 결과는 패턴(정규식) 기반 탐지로, 오탐이 포함될 수 있습니다.<br>
        · 이미지·도형 내 텍스트, 스캔 PDF는 탐지되지 않습니다.<br>
        · 탐지 결과를 참고하여 <strong>담당자가 직접 원본을 수정</strong>하고, 2단계 비교기를 사용하세요.
      </div>`;
  }

  const resultsEl = document.getElementById("results");
  resultsEl.innerHTML = html;
  resultsEl.style.display = "block";

  // 필터 버튼 이벤트
  resultsEl.querySelectorAll(".filter-btn").forEach(btn => {
    btn.addEventListener("click", () => {
      currentFilter = btn.dataset.filter;
      render();
    });
  });

  // 그룹 헤더 클릭(아코디언)
  resultsEl.querySelectorAll(".group-header").forEach(hdr => {
    hdr.addEventListener("click", () => {
      const key = hdr.closest(".group-card").dataset.group;
      expandedId = expandedId === key ? null : key;
      render();
    });
  });
}

// ─────────────────────────────────────────────────────────────
// 파일 처리
// ─────────────────────────────────────────────────────────────
async function handleFile(file) {
  // 상태 초기화
  document.getElementById("results").style.display = "none";
  document.getElementById("error-box").style.display = "none";
  allResults = []; expandedId = null;

  // 파일명 표시
  document.getElementById("drop-label").innerHTML = `
    <div class="fname">${esc(file.name)}</div>
    <div class="fmeta">${(file.size/1024).toFixed(1)} KB · 다른 파일을 드래그하거나 클릭하여 교체</div>`;

  document.getElementById("loading").style.display = "block";
  try {
    const chunks = await extractChunks(file);
    allResults = detect(chunks);
    render();
  } catch(e) {
    const eb = document.getElementById("error-box");
    eb.textContent = "⚠️ " + e.message;
    eb.style.display = "block";
  } finally {
    document.getElementById("loading").style.display = "none";
  }
}

// ─────────────────────────────────────────────────────────────
// 이벤트 바인딩
// ─────────────────────────────────────────────────────────────
const dropZone  = document.getElementById("drop-zone");
const fileInput = document.getElementById("file-input");

dropZone.addEventListener("click", () => fileInput.click());
fileInput.addEventListener("change", e => { if(e.target.files[0]) handleFile(e.target.files[0]); });

dropZone.addEventListener("dragover",  e => { e.preventDefault(); dropZone.classList.add("dragover"); });
dropZone.addEventListener("dragleave", () => dropZone.classList.remove("dragover"));
dropZone.addEventListener("drop", e => {
  e.preventDefault();
  dropZone.classList.remove("dragover");
  if(e.dataTransfer.files[0]) handleFile(e.dataTransfer.files[0]);
});
</script>
</body>
</html>
