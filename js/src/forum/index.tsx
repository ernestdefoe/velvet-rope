import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import classList from 'flarum/common/utils/classList';
import Button from 'flarum/common/components/Button';
import CommentPost from 'flarum/forum/components/CommentPost';
import DiscussionPage from 'flarum/forum/components/DiscussionPage';
import type Post from 'flarum/common/models/Post';
import type Discussion from 'flarum/common/models/Discussion';
import GatePrompt from '../common/components/GatePrompt';
import { t } from '../common/t';
import type { Gate, MeterInfo } from '../common/types';
import { act, primaryAction } from './act';

const gateOf = (post: Post): Gate | null => post.attribute<Gate | undefined>('guestGate') || null;
const meterOf = (post: Post): MeterInfo | null => post.discussion()?.attribute<MeterInfo | undefined>('guestGateMeter') || null;

/**
 * Give each locked placeholder the server left in a post its label and make
 * it a button. Runs when a post is drawn or redrawn, so posts that arrive
 * later (more replies loaded, a realtime reply) are covered too.
 */
function labelLocks(element: Element | undefined, gate: Gate | null) {
  if (!element || !gate) return;

  element.querySelectorAll<HTMLElement>('.GuestGate-lock:not([data-ready])').forEach((lock) => {
    const text = String(t(`lib.lock.${gate.reason === 'meter' ? 'guest' : gate.reason}.${lock.dataset.kind}`));

    const icon = document.createElement('i');
    icon.className = 'fas fa-lock';
    icon.setAttribute('aria-hidden', 'true');
    lock.prepend(icon);

    // A link that kept its words shows them; the prompt becomes its tooltip.
    if (lock.textContent?.trim()) {
      lock.title = text;
      lock.setAttribute('aria-label', lock.textContent.trim() + ': ' + text);
    } else {
      const label = document.createElement('span');
      label.className = 'GuestGate-lock-label';
      label.textContent = text;
      lock.append(label);
    }

    lock.setAttribute('role', 'button');
    lock.setAttribute('tabindex', '0');
    lock.dataset.ready = '';
    lock.dataset.reason = gate.reason;
    if (gate.upgradeUrl) lock.dataset.upgrade = gate.upgradeUrl;
  });
}

/** Whether the post before this one (if loaded) was read in full. */
function firstGated(post: Post): boolean {
  const number = post.number();
  const posts = post.discussion()?.posts() || [];
  const before = posts.find((p?: Post) => p?.number() === number - 1);
  const gate = before ? gateOf(before) : null;

  return !before || !gate || gate.mode === 'lock';
}

/** Free discussions left: say so once per discussion, when few are. */
const warned = new Set<string>();

function meterNotice(discussion: Discussion) {
  const meter = discussion.attribute<MeterInfo | undefined>('guestGateMeter');
  const id = discussion.id();

  if (!meter || !meter.counted || !id || warned.has(id) || meter.remaining > meter.warn) return;

  warned.add(id);

  const text = meter.remaining === 0 ? t(`forum.meter.last_${meter.period}`) : t(`forum.meter.left_${meter.period}`, { count: meter.remaining });
  const gate: Gate = { mode: 'lead', reason: 'meter' };

  app.alerts.show(
    {
      type: 'warning',
      controls: [
        <Button className="Button Button--link" onclick={() => act(primaryAction(gate), gate)}>
          {app.forum.attribute('allowSignUp') ? t('lib.prompt.sign_up') : t('lib.prompt.log_in')}
        </Button>,
      ],
    },
    text
  );
}

app.initializers.add('ernestdefoe-guest-gate', () => {
  extend(CommentPost.prototype, 'elementAttrs', function (this: CommentPost, attrs: any) {
    const gate = gateOf(this.attrs.post);

    if (gate) attrs.className = classList(attrs.className, 'GuestGate-post', `GuestGate-post--${gate.mode}`);
  });

  extend(CommentPost.prototype, 'content', function (this: CommentPost, items: any[]) {
    const post = this.attrs.post;
    const gate = gateOf(post);

    // Locked media explains itself; a preview or a hidden reply gets the
    // prompt: in full on the first gated post in view, one line after it.
    if (gate && gate.mode !== 'lock') {
      items.push(<GatePrompt gate={gate} meter={meterOf(post)} compact={!firstGated(post)} onaction={act} />);
    }
  });

  extend(CommentPost.prototype, ['oncreate', 'onupdate'], function (this: CommentPost) {
    labelLocks(this.element, gateOf(this.attrs.post));
  });

  extend(DiscussionPage.prototype, 'show', function (this: DiscussionPage, _: unknown, discussion: Discussion) {
    meterNotice(discussion);
  });

  // One listener for every locked placeholder on the page, present or future.
  const onLock = (e: Event) => {
    const lock = (e.target as Element | null)?.closest?.('.GuestGate-lock[data-ready]');
    if (!lock || (e instanceof KeyboardEvent && e.key !== 'Enter' && e.key !== ' ')) return;

    // Read from the placeholder itself: it may be in a post list, a search
    // result or a profile, outside any discussion's stream.
    const data = (lock as HTMLElement).dataset;
    const gate: Gate = { mode: 'lock', reason: data.reason as Gate['reason'], upgradeUrl: data.upgrade };

    e.preventDefault();
    act(primaryAction(gate), gate);
  };

  document.addEventListener('click', onLock);
  document.addEventListener('keydown', onLock);
});
