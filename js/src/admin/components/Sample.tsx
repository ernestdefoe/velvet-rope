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

function lock(kind: LockKind, gate: Gate) {
  const text = t(`lib.lock.${gate.reason === 'meter' ? 'guest' : gate.reason}.${kind}`);

  return (
    <span className="VelvetRope-lock" data-kind={kind}>
      <i className="fas fa-lock" aria-hidden="true" />
      <span className="VelvetRope-lock-label">{text}</span>
    </span>
  );
}

/** A sample post's media, locked or not. */
function media(locked: LockKind[], gate: Gate) {
  const on = (k: LockKind) => locked.includes(k);

  return (
    <div className="VelvetRope-sample-media">
      <p>
        {s('media_intro')}{' '}
        <a href="#" onclick={(e: Event) => e.preventDefault()}>
          {s('link_words')}
        </a>
      </p>
      {on('image') ? (
        lock('image', gate)
      ) : (
        <div className="VelvetRope-sample-image">
          <Icon name="far fa-image" />
        </div>
      )}
      {on('embed') ? (
        lock('embed', gate)
      ) : (
        <div className="VelvetRope-sample-embed">
          <Icon name="fas fa-play" />
        </div>
      )}
      {on('file') ? (
        lock('file', gate)
      ) : (
        <span className="VelvetRope-sample-file">
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
    body = <div className="VelvetRope-veil" style={{ '--gg-lines': 3 } as any} aria-hidden="true" />;
  } else if (mode === 'lead') {
    body = (
      <p>
        {cut(text, chars)}
        {chars < text.length && (
          <span className="VelvetRope-more" aria-hidden="true">
            …
          </span>
        )}
      </p>
    );
  } else {
    body = [<p>{text}</p>, number === 1 && media(gate ? locked : [], gate || { mode: 'lock', reason: 'guest' })];
  }

  return (
    <div className={classList('VelvetRope-sample-post', 'Post', 'CommentPost', gate && ['VelvetRope-post', `VelvetRope-post--${mode}`])}>
      <div className="VelvetRope-sample-author">
        <span className="VelvetRope-sample-avatar" />
        <span className="VelvetRope-sample-name">{s('author', { number })}</span>
      </div>
      <div className="Post-body">{body}</div>
      {gate && mode !== 'lock' && <GatePrompt gate={gate} meter={meter} compact={compact} preview />}
    </div>
  );
}

export function frame(title: any, posts: any[]) {
  return (
    <div className="VelvetRope-preview">
      <div className="VelvetRope-preview-title">
        <Icon name="far fa-eye" /> {title}
      </div>
      <div className="VelvetRope-preview-stream">{posts}</div>
    </div>
  );
}
