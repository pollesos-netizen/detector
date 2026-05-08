 import { useState, useCallback, useRef } from "react";
 import JSZip from "jszip";
import { parseHwp } from "./hwpParser";

import * as pdfjsLib from "pdfjs-dist";
import PdfWorker from "pdfjs-dist/build/pdf.worker.mjs?url";
pdfjsLib.GlobalWorkerOptions.workerSrc = PdfWorker;

// import { generateAndDownload } from "./reportGenerator";

// ── 정규식 패턴 정의 ──────────────────────────────────────────
const PATTERNS = [
  {
    id: "rrn",
    label: "주민등록번호",
    grade: "C",
    action: "삭제",
    regex: /\b\d{6}[-–]\d{7}\b/g,
    desc: "고유식별정보 — 어떠한 형태로도 외부 AI 입력 불가",
  },
  {
    id: "passport",
    label: "여권번호",
    grade: "C",
    action: "삭제",
    regex: /\b[A-Z]{1,2}\d{7,9}\b/g,
    desc: "고유식별정보",
  },
  {
    id: "ip",
    label: "내부 IP 주소",
    grade: "C",
    action: "삭제",
    regex: /\b(10\.\d{1,3}\.\d{1,3}\.\d{1,3}|172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}|192\.168\.\d{1,3}\.\d{1,3})\b/g,
    desc: "내부 네트워크 정보 — 사이버 공격 악용 위험",
  },
  {
    id: "phone",
    label: "전화번호",
    grade: "S",
    action: "마스킹",
    regex: /\b0\d{1,2}[-–·]?\d{3,4}[-–·]?\d{4}\b/g,
    desc: "직접 식별 가능한 개인정보",
  },
  {
    id: "email",
    label: "이메일 주소",
    grade: "S",
    action: "마스킹",
    regex: /\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/g,
    desc: "직접 식별 가능한 개인정보",
  },
  {
    id: "empid",
    label: "사번",
    grade: "S",
    action: "마스킹",
    regex: /\b[A-Za-z]{2}\d{6}\b/g,
    desc: "직접 식별 가능한 개인정보",
  },
  {
    id: "account",
    label: "계좌번호",
    grade: "S",
    action: "마스킹",
    regex: /\b(?:\d{3}-\d{2}-\d{4}-\d{3}|\d{4}-\d{3}-\d{6}|\d{3}-\d{6}-\d{5}|\d{3}-\d{2}-\d{6}|\d{3}-\d{6}-\d{2}-\d{3}|\d{3}-\d{4}-\d{4}-\d{2}|\d{4}-\d{4}-\d{4}-\d{1}|\d{6}-\d{2}-\d{6}|\d{4}-\d{2}-\d{7}|\d{3}-\d{6}-\d{3}|\d{2}-\d{2}-\d{6}|\d{3}-\d{2}-\d{6}-\d{1}|\d{3}-\d{4}-\d{4}-\d{3})\b|\b\d{10,14}\b/g,
    desc: "계좌번호 (국민·우리·하나·신한 등 16개 은행 패턴 / 하이픈 없는 10~14자리 포함)",
  },
  {
    id: "freq",
    label: "무선 주파수",
    grade: "S",
    action: "치환",
    regex: /\b\d{2,3}\.\d{3,4}\s*[MmGg][Hh][Zz]\b/g,
    desc: "통신 보안 정보 — 도청·혼신 위험",
  },
  {
    id: "coord",
    label: "정밀 위치(키로정)",
    grade: "S",
    action: "범주화",
    regex: /\b\d{1,3}[Kk]\d{3}\b/g,
    desc: "정밀 위치정보 — 범위(예: 37K~38K)로 범주화 권장",
  },
  {
    id: "datetime_precise",
    label: "정밀 시각",
    grade: "S",
    action: "범주화",
    regex: /\b([01]\d|2[0-3]):[0-5]\d:[0-5]\d\b/g,
    desc: "정밀 시각 — 오전/오후 등 범주화 권장",
  },
  {
    id: "name_ko",
    label: "한국인 성명 추정",
    grade: "S",
    action: "마스킹",
    regex: /[가-힣]{2,4}(?:\s*(?:씨|님|부장|차장|과장|대리|주임|사원|팀장|본부장|이사|상무|전무|사장|기관사|기장|승무원))/g,
    desc: "직책·호칭과 결합된 성명 — 직접 식별 가능",
  },
  {
    id: "vlan",
    label: "VLAN/포트 정보",
    grade: "C",
    action: "삭제",
    regex: /\bVLAN\s*\d+\b|\bport\s*\d{2,5}\b/gi,
    desc: "내부 네트워크 구성 정보",
  },
];

// ── 파일 파서 ─────────────────────────────────────────────────
// 각 파서는 { text, location } 청크 배열을 반환
// location 형식: PPTX→"슬라이드 N", HWPX→"섹션 N", DOCX→"N번째 단락", XLSX→"시트명"

async function extractChunks(file) {
  const name = file.name.toLowerCase();
  if (name.endsWith(".hwp"))  return await parseHwp(file);
  if (name.endsWith(".hwpx")) return await parseHwpx(file);
  if (name.endsWith(".docx")) return await parseDocx(file);
  if (name.endsWith(".pptx")) return await parsePptx(file);
  if (name.endsWith(".xlsx")) return await parseXlsx(file);
  if (name.endsWith(".pdf"))  return await parsePdf(file);
  throw new Error("지원하지 않는 파일 형식입니다.");
}

async function readZip(file) {
  const buf = await file.arrayBuffer();
  return await JSZip.loadAsync(buf);
}

async function parseDocx(file) {
  const zip = await readZip(file);
  if (!zip.files["word/document.xml"]) throw new Error("word/document.xml 없음");
  const xml = await zip.files["word/document.xml"].async("string");
  const paraMatches = [...xml.matchAll(/<w:p[ >][\s\S]*?<\/w:p>/g)];
  const chunks = [];
  let paraNum = 0;
  for (const m of paraMatches) {
    const ts = [...m[0].matchAll(/<w:t[^>]*>(.*?)<\/w:t>/g)].map((t) => t[1]);
    const text = ts.join("").trim();
    if (text) { paraNum++; chunks.push({ text, location: `${paraNum}번째 단락` }); }
  }
  return chunks;
}

async function parsePptx(file) {
  const zip = await readZip(file);
  const slides = Object.keys(zip.files)
    .filter((k) => k.startsWith("ppt/slides/slide") && k.endsWith(".xml"))
    .sort((a, b) => parseInt(a.match(/slide(\d+)/)?.[1] || 0) - parseInt(b.match(/slide(\d+)/)?.[1] || 0));
  const chunks = [];
  for (let i = 0; i < slides.length; i++) {
    const xml = await zip.files[slides[i]].async("string");
    const paraMatches = [...xml.matchAll(/<a:p[ >][\s\S]*?<\/a:p>/g)];
    let paraNum = 0;
    for (const m of paraMatches) {
      const ts = [...m[0].matchAll(/<a:t[^>]*>(.*?)<\/a:t>/g)].map((t) => t[1]);
      const text = ts.join("").trim();
      if (text) {
        paraNum++;
        chunks.push({ text, location: `슬라이드 ${i + 1} · ${paraNum}번째 단락` });
      }
    }
  }
  return chunks;
}

async function parseHwpx(file) {
  const zip = await readZip(file);
  const sections = Object.keys(zip.files)
    .filter((k) => k.startsWith("Contents/section") && k.endsWith(".xml"))
    .sort();
  const chunks = [];
  let paraNum = 0;
  for (const secFile of sections) {
    const xml = await zip.files[secFile].async("string");
    const paraMatches = [...xml.matchAll(/<hp:p[ >][\s\S]*?<\/hp:p>/g)];
    for (const m of paraMatches) {
      // hp:tbl 과 혼동되지 않도록 \b(word boundary) 사용
      const ts = [...m[0].matchAll(/<hp:t\b[^>]*>([^<]*)<\/hp:t>/g)].map((t) => t[1]);
      const text = ts.join("").trim();
      if (text) { paraNum++; chunks.push({ text, location: `${paraNum}번째 단락` }); }
    }
  }
  return chunks;
}

async function parseXlsx(file) {
  const zip = await readZip(file);
  // 시트 이름 매핑 (workbook.xml)
  const sheetNames = {};
  if (zip.files["xl/workbook.xml"]) {
    const wbXml = await zip.files["xl/workbook.xml"].async("string");
    const nameMatches = [...wbXml.matchAll(/sheetId="(\d+)"[^>]*name="([^"]+)"/g)];
    nameMatches.forEach((m) => { sheetNames[m[1]] = m[2]; });
  }
  // shared strings
  let shared = [];
  if (zip.files["xl/sharedStrings.xml"]) {
    const xml = await zip.files["xl/sharedStrings.xml"].async("string");
    shared = [...xml.matchAll(/<t[^>]*>([^<]*)<\/t>/g)].map((m) => m[1]);
  }
  // 시트별 청크
  const sheets = Object.keys(zip.files)
    .filter((k) => k.startsWith("xl/worksheets/sheet") && k.endsWith(".xml"))
    .sort();
  const chunks = [];
  for (let i = 0; i < sheets.length; i++) {
    const sheetNum = sheets[i].match(/sheet(\d+)/)?.[1] || String(i + 1);
    const sheetName = sheetNames[sheetNum] || `Sheet${sheetNum}`;
    const xml = await zip.files[sheets[i]].async("string");
    // 셀 값 추출: v 태그 값을 shared strings와 매핑
    const cellTexts = [];
    const cellMatches = [...xml.matchAll(/<c[^>]*t="s"[^>]*><v>(\d+)<\/v>/g)];
    cellMatches.forEach((m) => {
      const idx = parseInt(m[1]);
      if (shared[idx]) cellTexts.push(shared[idx]);
    });
    // 인라인 문자열
    const inlineMatches = [...xml.matchAll(/<t[^>]*>([^<]+)<\/t>/g)];
    inlineMatches.forEach((m) => { if (m[1].trim()) cellTexts.push(m[1]); });
    const text = cellTexts.join(" ").trim();
    if (text) chunks.push({ text, location: sheetName });
  }
  // shared strings도 별도 검색 (시트에서 못 잡은 경우 대비)
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
    // 같은 y좌표 기준으로 줄 묶기
    const lines = {};
    for (const item of content.items) {
      if (!item.str.trim()) continue;
      const y = Math.round(item.transform[5]);
      if (!lines[y]) lines[y] = [];
      lines[y].push(item.str);
    }
    // y좌표 내림차순 정렬 (위→아래)
    const sorted = Object.keys(lines)
      .sort((a, b) => b - a)
      .map((y) => lines[y].join("").trim())
      .filter((t) => t);
    sorted.forEach((text, idx) => {
      chunks.push({ text, location: `${i}페이지 · ${idx + 1}번째 줄` });
    });
  }
  return chunks;
}


// ── 탐지 엔진 ─────────────────────────────────────────────────
function detect(chunks) {
  const results = [];
  for (const { text, location } of chunks) {
    for (const p of PATTERNS) {
      const regex = new RegExp(p.regex.source, p.regex.flags);
      const matches = [...text.matchAll(regex)];
      for (const m of matches) {
        const start = Math.max(0, m.index - 40);
        const end = Math.min(text.length, m.index + m[0].length + 40);
        const context = text.slice(start, end).replace(/\s+/g, " ").trim();
        results.push({
          patternId: p.id,
          label: p.label,
          grade: p.grade,
          action: p.action,
          desc: p.desc,
          matched: m[0],
          context,
          location,
        });
      }
    }
  }
  return results;
}

// ── 색상 헬퍼 ─────────────────────────────────────────────────
const GRADE_COLOR = {
  C: { bg: "#FEE2E2", border: "#EF4444", text: "#B91C1C", badge: "#DC2626" },
  S: { bg: "#FEF3C7", border: "#F59E0B", text: "#92400E", badge: "#D97706" },
};
const ACTION_COLOR = {
  삭제: "#DC2626",
  마스킹: "#7C3AED",
  치환: "#2563EB",
  범주화: "#059669",
};

// ── 컴포넌트 ──────────────────────────────────────────────────
export default function Detector() {
  const [file, setFile] = useState(null);
  const [loading, setLoading] = useState(false);
  const [results, setResults] = useState(null);
  const [error, setError] = useState(null);
  const [filter, setFilter] = useState("ALL");
  const [expandedId, setExpandedId] = useState(null);
  const inputRef = useRef();

  const handleFile = useCallback(async (f) => {
    setFile(f);
    setResults(null);
    setError(null);
    setLoading(true);
    try {
      const chunks = await extractChunks(f);
      const findings = detect(chunks);
      setResults(findings);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, []);

  const onDrop = useCallback(
    (e) => {
      e.preventDefault();
      const f = e.dataTransfer.files[0];
      if (f) handleFile(f);
    },
    [handleFile]
  );

  const onPick = (e) => {
    const f = e.target.files[0];
    if (f) handleFile(f);
  };

  const filtered =
    results &&
    (filter === "ALL" ? results : results.filter((r) => r.grade === filter));

  const countByGrade = (g) => results?.filter((r) => r.grade === g).length ?? 0;

  // 패턴별 그룹
  const grouped = filtered
    ? filtered.reduce((acc, r) => {
        const key = r.patternId;
        if (!acc[key]) acc[key] = { label: r.label, grade: r.grade, action: r.action, desc: r.desc, items: [] };
        acc[key].items.push(r);
        return acc;
      }, {})
    : {};

  return (
    <div style={{ fontFamily: "'Pretendard', 'Apple SD Gothic Neo', sans-serif", minHeight: "100vh", background: "#F8FAFC", color: "#0F172A" }}>
      {/* 헤더 */}
      <div style={{ background: "#1B2E5E", color: "#fff", padding: "20px 28px", display: "flex", alignItems: "center", gap: 12 }}>
        <div style={{ width: 36, height: 36, background: "#2563EB", borderRadius: 8, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 18 }}>🔍</div>
        <div>
          <div style={{ fontWeight: 700, fontSize: 16 }}>민감정보 탐지기 — 1단계</div>
          <div style={{ fontSize: 12, color: "#94A3B8", marginTop: 2 }}>HWP · HWPX · DOCX · PPTX · XLSX · PDF 지원</div>
        </div>
        <div style={{ marginLeft: "auto", fontSize: 12, color: "#64748B", background: "#0F172A", borderRadius: 6, padding: "4px 10px" }}>
          패턴(정규식) 기반
        </div>
      </div>

      <div style={{ maxWidth: 900, margin: "0 auto", padding: "28px 20px" }}>

        {/* 업로드 영역 */}
        <div
          onDrop={onDrop}
          onDragOver={(e) => e.preventDefault()}
          onClick={() => inputRef.current.click()}
          style={{
            border: "2px dashed #CBD5E1",
            borderRadius: 16,
            padding: "40px 24px",
            textAlign: "center",
            cursor: "pointer",
            background: "#fff",
            transition: "border-color 0.2s",
            marginBottom: 24,
          }}
          onMouseEnter={(e) => (e.currentTarget.style.borderColor = "#2563EB")}
          onMouseLeave={(e) => (e.currentTarget.style.borderColor = "#CBD5E1")}
        >
          <input ref={inputRef} type="file" accept=".hwp,.hwpx,.docx,.pptx,.xlsx,.pdf" style={{ display: "none" }} onChange={onPick} />
          <div style={{ fontSize: 36, marginBottom: 12 }}>📄</div>
          {file ? (
            <div>
              <div style={{ fontWeight: 600, color: "#1B2E5E" }}>{file.name}</div>
              <div style={{ fontSize: 13, color: "#64748B", marginTop: 4 }}>
                {(file.size / 1024).toFixed(1)} KB · 다른 파일을 드래그하거나 클릭하여 교체
              </div>
            </div>
          ) : (
            <div>
              <div style={{ fontWeight: 600, fontSize: 15, color: "#334155" }}>파일을 드래그하거나 클릭하여 선택</div>
              <div style={{ fontSize: 13, color: "#94A3B8", marginTop: 6 }}>
                HWP · HWPX · DOCX · PPTX · XLSX · PDF
              </div>
            </div>
          )}
        </div>

        {/* 로딩 */}
        {loading && (
          <div style={{ textAlign: "center", padding: "40px 0", color: "#64748B" }}>
            <div style={{ fontSize: 28, marginBottom: 12 }}>⏳</div>
            <div>파일 분석 중...</div>
          </div>
        )}

        {/* 에러 */}
        {error && (
          <div style={{ background: "#FEE2E2", border: "1px solid #EF4444", borderRadius: 10, padding: "14px 18px", color: "#B91C1C", marginBottom: 16 }}>
            ⚠️ {error}
          </div>
        )}

        {/* 결과 */}
        {results && !loading && (
          <>
            {/* 요약 카드 */}
            <div style={{ display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gap: 14, marginBottom: 24 }}>
              {[
                { label: "전체 탐지", count: results.length, color: "#1B2E5E", bg: "#EEF2FF", icon: "📋" },
                { label: "C급 (기밀)", count: countByGrade("C"), color: "#B91C1C", bg: "#FEE2E2", icon: "🔴" },
                { label: "S급 (민감)", count: countByGrade("S"), color: "#92400E", bg: "#FEF3C7", icon: "🟡" },
              ].map((s) => (
                <div key={s.label} style={{ background: s.bg, borderRadius: 12, padding: "18px 20px" }}>
                  <div style={{ fontSize: 13, color: s.color, fontWeight: 600 }}>{s.icon} {s.label}</div>
                  <div style={{ fontSize: 32, fontWeight: 800, color: s.color, marginTop: 6 }}>{s.count}</div>
                  <div style={{ fontSize: 12, color: s.color, opacity: 0.7 }}>건 발견</div>
                </div>
              ))}
            </div>

            {results.length === 0 ? (
              <div style={{ background: "#F0FDF4", border: "1px solid #86EFAC", borderRadius: 12, padding: "28px", textAlign: "center", color: "#166534" }}>
                <div style={{ fontSize: 28, marginBottom: 8 }}>✅</div>
                <div style={{ fontWeight: 600 }}>패턴 기반 탐지 결과 민감정보가 발견되지 않았습니다.</div>
                <div style={{ fontSize: 13, marginTop: 6, color: "#4ADE80" }}>단, 문맥 판단이 필요한 항목은 직접 검토하시기 바랍니다.</div>
              </div>
            ) : (
              <>
                {/* 필터 탭 */}
                <div style={{ display: "flex", gap: 8, marginBottom: 18 }}>
                  {["ALL", "C", "S"].map((g) => (
                    <button
                      key={g}
                      onClick={() => setFilter(g)}
                      style={{
                        padding: "6px 16px",
                        borderRadius: 20,
                        border: "none",
                        cursor: "pointer",
                        fontWeight: filter === g ? 700 : 400,
                        background: filter === g
                          ? g === "C" ? "#DC2626" : g === "S" ? "#D97706" : "#1B2E5E"
                          : "#E2E8F0",
                        color: filter === g ? "#fff" : "#475569",
                        fontSize: 13,
                        transition: "all 0.15s",
                      }}
                    >
                      {g === "ALL" ? `전체 (${results.length})` : g === "C" ? `C급 (${countByGrade("C")})` : `S급 (${countByGrade("S")})`}
                    </button>
                  ))}
                </div>

                {/* 그룹별 카드 */}
                {Object.entries(grouped).map(([key, group]) => {
                  const gc = GRADE_COLOR[group.grade];
                  const isOpen = expandedId === key;
                  return (
                    <div
                      key={key}
                      style={{
                        background: "#fff",
                        borderRadius: 12,
                        border: `1px solid ${isOpen ? gc.border : "#E2E8F0"}`,
                        marginBottom: 12,
                        overflow: "hidden",
                        transition: "border-color 0.2s",
                      }}
                    >
                      {/* 그룹 헤더 */}
                      <div
                        onClick={() => setExpandedId(isOpen ? null : key)}
                        style={{
                          display: "flex",
                          alignItems: "center",
                          padding: "14px 18px",
                          cursor: "pointer",
                          background: isOpen ? gc.bg : "#fff",
                          transition: "background 0.2s",
                          gap: 10,
                        }}
                      >
                        {/* 등급 배지 */}
                        <span style={{
                          background: gc.badge, color: "#fff",
                          borderRadius: 6, padding: "2px 8px",
                          fontSize: 12, fontWeight: 700, flexShrink: 0,
                        }}>
                          {group.grade}급
                        </span>
                        {/* 항목명 */}
                        <span style={{ fontWeight: 700, fontSize: 15, color: "#0F172A", flex: 1 }}>
                          {group.label}
                        </span>
                        {/* 처리방법 배지 */}
                        <span style={{
                          background: ACTION_COLOR[group.action] + "18",
                          color: ACTION_COLOR[group.action],
                          border: `1px solid ${ACTION_COLOR[group.action]}40`,
                          borderRadius: 6, padding: "2px 10px",
                          fontSize: 12, fontWeight: 600, flexShrink: 0,
                        }}>
                          {group.action} 권장
                        </span>
                        {/* 건수 */}
                        <span style={{
                          background: "#F1F5F9", color: "#475569",
                          borderRadius: 20, padding: "2px 10px",
                          fontSize: 13, fontWeight: 700, flexShrink: 0,
                        }}>
                          {group.items.length}건
                        </span>
                        <span style={{ color: "#94A3B8", fontSize: 14, flexShrink: 0 }}>
                          {isOpen ? "▲" : "▼"}
                        </span>
                      </div>

                      {/* 설명 */}
                      {isOpen && (
                        <div style={{ padding: "0 18px" }}>
                          <div style={{
                            fontSize: 13, color: gc.text,
                            background: gc.bg, borderRadius: 8,
                            padding: "8px 12px", marginBottom: 12,
                          }}>
                            ℹ️ {group.desc}
                          </div>
                          {/* 탐지 목록 */}
                          <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 13, marginBottom: 14 }}>
                            <thead>
                              <tr style={{ background: "#F8FAFC" }}>
                                <th style={{ padding: "8px 10px", textAlign: "left", color: "#64748B", fontWeight: 600, width: 160, borderBottom: "1px solid #E2E8F0" }}>탐지 값</th>
                                <th style={{ padding: "8px 10px", textAlign: "left", color: "#64748B", fontWeight: 600, width: 120, borderBottom: "1px solid #E2E8F0" }}>위치</th>
                                <th style={{ padding: "8px 10px", textAlign: "left", color: "#64748B", fontWeight: 600, borderBottom: "1px solid #E2E8F0" }}>전후 문맥</th>
                              </tr>
                            </thead>
                            <tbody>
                              {group.items.map((item, i) => (
                                <tr key={i} style={{ borderBottom: "1px solid #F1F5F9" }}>
                                  <td style={{ padding: "8px 10px", fontFamily: "monospace", fontWeight: 700, color: gc.badge, verticalAlign: "top" }}>
                                    {item.matched}
                                  </td>
                                  <td style={{ padding: "8px 10px", verticalAlign: "top" }}>
                                    <span style={{
                                      background: "#EEF2FF", color: "#3730A3",
                                      borderRadius: 5, padding: "2px 7px",
                                      fontSize: 12, fontWeight: 600, whiteSpace: "nowrap",
                                    }}>
                                      {item.location}
                                    </span>
                                  </td>
                                  <td style={{ padding: "8px 10px", color: "#475569", lineHeight: 1.6 }}>
                                    {item.context.split(item.matched).map((part, idx, arr) =>
                                      idx < arr.length - 1
                                        ? [
                                            <span key={`t${idx}`}>{part}</span>,
                                            <mark key={`m${idx}`} style={{ background: gc.bg, color: gc.badge, borderRadius: 3, padding: "0 2px" }}>
                                              {item.matched}
                                            </mark>,
                                          ]
                                        : <span key={`t${idx}`}>{part}</span>
                                    )}
                                  </td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      )}
                    </div>
                  );
                })}

                {/* 주의사항 */}
                <div style={{
                  marginTop: 20, background: "#F8FAFC",
                  border: "1px solid #CBD5E1", borderRadius: 12,
                  padding: "14px 18px", fontSize: 13, color: "#64748B",
                  lineHeight: 1.7,
                }}>
                  <strong style={{ color: "#334155" }}>⚠️ 주의사항</strong><br />
                  · 위 결과는 패턴(정규식) 기반 탐지로, 오탐이 포함될 수 있습니다.<br />
                  · 이미지·도형 내 텍스트, 스캔 PDF는 탐지되지 않습니다.<br />
                  · 탐지 결과를 참고하여 <strong>담당자가 직접 원본을 수정</strong>하고, 2단계 비교기를 사용하세요.
                </div>
              </>
            )}
          </>
        )}
      </div>
    </div>
  );
}
