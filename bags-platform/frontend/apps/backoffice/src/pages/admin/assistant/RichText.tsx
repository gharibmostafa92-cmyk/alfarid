import { useState, type ReactNode } from 'react';
import { Icon } from './icons';

// XSS mitigation: avoids dangerouslySetInnerHTML by directly constructing React elements from Markdown tokens.
// Supports paragraphs, headings (#), lists (- / 1.), blockquotes (>), code blocks, and bold formatting.

function inlineCodeOnly(text: string, key: string): ReactNode[] {
  const out: ReactNode[] = [];
  const re = /`([^`\n]+)`/g;
  let last = 0; let m: RegExpExecArray | null; let i = 0;
  while ((m = re.exec(text))) {
    if (m.index > last) out.push(text.slice(last, m.index));
    out.push(<code key={`${key}-c${i++}`}>{m[1]}</code>);
    last = m.index + m[0].length;
  }
  if (last < text.length) out.push(text.slice(last));
  return out;
}

function inline(text: string, key: string): ReactNode[] {
  const out: ReactNode[] = [];
  // Bold may contain single asterisks (e.g. **5 * 120**) or inline code (`SKU`).
  const re = /(`[^`\n]+`|\*\*(?:(?!\*\*)[^\n])+\*\*)/g;
  let last = 0; let m: RegExpExecArray | null; let i = 0;
  while ((m = re.exec(text))) {
    if (m.index > last) out.push(text.slice(last, m.index));
    const tok = m[0];
    if (tok.startsWith('`')) out.push(<code key={`${key}-c${i++}`}>{tok.slice(1, -1)}</code>);
    else out.push(<strong key={`${key}-b${i++}`}>{inlineCodeOnly(tok.slice(2, -2), `${key}-bi${i}`)}</strong>);
    last = m.index + tok.length;
  }
  if (last < text.length) out.push(text.slice(last));
  return out;
}

function CodeBlock({ lang, code }: { lang: string; code: string }) {
  const [copied, setCopied] = useState(false);
  return (
    <figure className="as-code">
      <div className="as-code-head">
        <span>{lang || 'code'}</span>
        <button
          type="button"
          className="as-btn as-btn--ghost as-btn--sm"
          onClick={() => { void navigator.clipboard?.writeText(code).then(() => { setCopied(true); setTimeout(() => setCopied(false), 1500); }).catch(() => undefined); }}
        >
          <Icon name={copied ? 'check' : 'copy'} size={14} />{copied ? 'Copied' : 'Copy'}
        </button>
      </div>
      <pre><code>{code}</code></pre>
    </figure>
  );
}

type ParsedList = { ordered: boolean; items: { text: string; children: ParsedList[] }[] };
const listItem = (line: string) => {
  const match = /^(\s*)([-*•]|\d+[.)])\s+(.*)$/.exec(line);
  if (!match) return null;
  return { indent: match[1].replace(/\t/g, '    ').length, ordered: /^\d/.test(match[2]), text: match[3] };
};

function parseList(lines: string[], start: number, indent = listItem(lines[start])?.indent ?? 0): { list: ParsedList; next: number } {
  const first = listItem(lines[start]);
  const list: ParsedList = { ordered: first?.ordered ?? false, items: [] };
  let index = start;
  while (index < lines.length) {
    const line = lines[index];
    const parsed = listItem(line);
    if (parsed) {
      if (parsed.indent < indent) break;
      if (parsed.indent > indent) {
        const current = list.items.at(-1);
        if (!current) break;
        const nested = parseList(lines, index, parsed.indent);
        current.children.push(nested.list);
        index = nested.next;
        continue;
      }
      if (parsed.ordered !== list.ordered) break;
      list.items.push({ text: parsed.text, children: [] });
      index++;
      continue;
    }
    if (!line.trim()) break;
    const leading = line.match(/^\s*/)?.[0].replace(/\t/g, '    ').length ?? 0;
    const current = list.items.at(-1);
    if (leading > indent && current) {
      current.text += `\n${line.trim()}`;
      index++;
      continue;
    }
    break;
  }
  return { list, next: index };
}

function RenderList({ list, keyPrefix }: { list: ParsedList; keyPrefix: string }) {
  const Tag = list.ordered ? 'ol' : 'ul';
  return <Tag>{list.items.map((item, index) => <li key={`${keyPrefix}-${index}`}>{inline(item.text, `${keyPrefix}-${index}`)}{item.children.map((child, childIndex) => <RenderList key={`${keyPrefix}-${index}-${childIndex}`} list={child} keyPrefix={`${keyPrefix}-${index}-${childIndex}`} />)}</li>)}</Tag>;
}

export function RichText({ text }: { text: string | null }) {
  if (!text) return null;
  const lines = text.replace(/\r\n/g, '\n').split('\n');
  const blocks: ReactNode[] = [];
  let i = 0; let k = 0;
  while (i < lines.length) {
    const line = lines[i];
    const fence = line.match(/^```\s*([\w-]*)\s*$/);
    if (fence) {
      const buf: string[] = []; i++;
      while (i < lines.length && !/^```\s*$/.test(lines[i])) buf.push(lines[i++]);
      i++;
      blocks.push(<CodeBlock key={k++} lang={fence[1]} code={buf.join('\n')} />);
      continue;
    }
    if (/^\s*$/.test(line)) { i++; continue; }
    const h = line.match(/^#{1,4}\s+(.*)$/);
    if (h) { blocks.push(<h4 key={k++}>{inline(h[1], `h${k}`)}</h4>); i++; continue; }
    if (listItem(line)) {
      const parsed = parseList(lines, i);
      blocks.push(<RenderList key={k++} list={parsed.list} keyPrefix={`list-${k}`} />);
      i = parsed.next;
      continue;
    }
    if (/^>\s?/.test(line)) {
      const buf: string[] = [];
      while (i < lines.length && /^>\s?/.test(lines[i])) buf.push(lines[i++].replace(/^>\s?/, ''));
      blocks.push(<blockquote key={k++}>{inline(buf.join('\n'), `q${k}`)}</blockquote>);
      continue;
    }
    const buf: string[] = [];
    while (i < lines.length && lines[i].trim() !== '' && !/^(```|#{1,4}\s|\s*[-*•]\s+|\s*\d+[.)]\s+|>)/.test(lines[i])) buf.push(lines[i++]);
    if (!buf.length) { buf.push(lines[i++]); }
    blocks.push(<p key={k++}>{inline(buf.join('\n'), `p${k}`)}</p>);
  }
  return <div className="as-rich">{blocks}</div>;
}
