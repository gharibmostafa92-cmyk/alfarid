import { useState, type ReactNode } from 'react';
import { Icon } from './icons';

// H6 (محفوظ): لا dangerouslySetInnerHTML نهائيًا. ده Markdown parser صغير بيبني
// React elements مباشرة، فأي HTML جوه أسماء الفروع/الموظفين يتعرض كنص عادي.
// بيدعم: فقرات، عناوين (#)، قوائم (- / 1.)، اقتباس (>)، كود (``` و `inline`)، **bold**.

function inline(text: string, key: string): ReactNode[] {
  const out: ReactNode[] = [];
  const re = /(`[^`\n]+`|\*\*[^*\n]+\*\*)/g;
  let last = 0; let m: RegExpExecArray | null; let i = 0;
  while ((m = re.exec(text))) {
    if (m.index > last) out.push(text.slice(last, m.index));
    const tok = m[0];
    if (tok.startsWith('`')) out.push(<code key={`${key}-c${i++}`}>{tok.slice(1, -1)}</code>);
    else out.push(<strong key={`${key}-b${i++}`}>{tok.slice(2, -2)}</strong>);
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
    if (/^\s*[-*•]\s+/.test(line)) {
      const items: string[] = [];
      while (i < lines.length && /^\s*[-*•]\s+/.test(lines[i])) items.push(lines[i++].replace(/^\s*[-*•]\s+/, ''));
      blocks.push(<ul key={k++}>{items.map((t, j) => <li key={j}>{inline(t, `u${k}-${j}`)}</li>)}</ul>);
      continue;
    }
    if (/^\s*\d+[.)]\s+/.test(line)) {
      const items: string[] = [];
      while (i < lines.length && /^\s*\d+[.)]\s+/.test(lines[i])) items.push(lines[i++].replace(/^\s*\d+[.)]\s+/, ''));
      blocks.push(<ol key={k++}>{items.map((t, j) => <li key={j}>{inline(t, `o${k}-${j}`)}</li>)}</ol>);
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
