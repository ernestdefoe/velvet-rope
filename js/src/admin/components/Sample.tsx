import Icon from 'flarum/common/components/Icon';
import classList from 'flarum/common/utils/classList';
import GatePrompt from '../../common/components/GatePrompt';
import { t } from '../../common/t';
import type { Gate, LockKind, MeterInfo } from '../../common/types';

const s = (k: string, p?: any) => t('admin.preview.' + k, p);

/** The first `chars` characters of `text`, cut at a word, as the server cuts. */
export function cut(text: string, chars: number): string {
  if (text.length <= chars) return text;

  const part = text.slice(0, chars);
  const space = part.lastIndexOf(' ');

  return (/\s/.test(text.charAt(chars)) || space <= chars / 2 ? part : part.slice(0, space)).trimEnd();
}

function lock(kind: LockKind, gate: Gate, words?: string) {
  const text = t(`lib.lock.${gate.reason === 'meter' ? 'guest' : gate.reason}.${kind}`);

  return (
    <span className="GuestGate-lock" data-kind={kind} title={words ? String(text) : undefined}>
      <i className="fas fa-lock" aria-hidden="true" />
      {words || <span className="GuestGate-lock-label">{text}</span>}
    </span>
  );
}

/** A sample post's media, locked or not. */
function media(locked: LockKind[], gate: Gate) {
  const on = (k: LockKind) => locked.includes(k);

  return (
    <div className="GuestGate-sample-media">
      <p>
        {s('media_intro')}{' '}
        {on('link') ? (
          lock('link', gate, String(s('link_words')))
        ) : (
          <a href="#" onclick={(e: Event) => e.preventDefault()}>
            {s('link_words')}
          </a>
        )}
      </p>
      {on('image') ? (
        lock('image', gate)
      ) : (
        <div className="GuestGate-sample-image">
          <Icon name="far fa-image" />
        </div>
      )}
      {on('embed') ? (
        lock('embed', gate)
      ) : (
        <div className="GuestGate-sample-embed">
          <Icon name="fas fa-play" />
        </div>
      )}
      {on('file') ? (
        lock('file', gate)
      ) : (
        <span className="GuestGate-sample-file">
          <Icon name="fas fa-file-pdf" /> {s('file_name')}
        </span>
      )}
    </div>
  );
}

export interface SamplePostAttrs {
  number: number;
  /** null: shown whole. */
  gate: Gate | null;
  locked: LockKind[];
  chars?: number;
  meter?: MeterInfo | null;
  /** The one-line prompt (every gated post after the first). */
  compact?: boolean;
}

/** One post of the preview discussion, drawn with the forum's own classes. */
export function samplePost({ number, gate, locked, chars = 0, meter, compact = false }: SamplePostAttrs) {
  const text = String(s(number === 1 ? 'first_post' : 'reply'));
  const mode = gate?.mode;

  let body;
  if (mode === 'hide') {
    body = <div className="GuestGate-veil" style={{ '--gg-lines': 3 } as any} aria-hidden="true" />;
  } else if (mode === 'lead') {
    body = (
      <p>
        {cut(text, chars)}
        {chars < text.length && (
          <span className="GuestGate-more" aria-hidden="true">
            …
          </span>
        )}
      </p>
    );
  } else {
    body = [<p>{text}</p>, number === 1 && media(gate ? locked : [], gate || { mode: 'lock', reason: 'guest' })];
  }

  return (
    <div className={classList('GuestGate-sample-post', 'Post', 'CommentPost', gate && ['GuestGate-post', `GuestGate-post--${mode}`])}>
      <div className="GuestGate-sample-author">
        <span className="GuestGate-sample-avatar" />
        <span className="GuestGate-sample-name">{s('author', { number })}</span>
      </div>
      <div className="Post-body">{body}</div>
      {gate && mode !== 'lock' && <GatePrompt gate={gate} meter={meter} compact={compact} preview />}
    </div>
  );
}

export function frame(title: any, posts: any[]) {
  return (
    <div className="GuestGate-preview">
      <div className="GuestGate-preview-title">
        <Icon name="far fa-eye" /> {title}
      </div>
      <div className="GuestGate-preview-stream">{posts}</div>
    </div>
  );
}
