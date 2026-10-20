import Component, { type ComponentAttrs } from 'flarum/common/Component';
import { t } from '../../common/t';
import type { Gate } from '../../common/types';
import type { Rule } from './RulesEditor';
import { frame, samplePost } from './Sample';

/**
 * A live preview of one rule: the first posts of a sample discussion as
 * someone outside its groups sees them. Members appear as the audience when
 * the rule lets no member in; otherwise guests.
 */
export default class RulePreview extends Component<ComponentAttrs & { rule: Rule }> {
  view() {
    const rule = this.attrs.rule;
    const reason: Gate['reason'] = rule.readers.includes(3) ? 'guest' : 'member';
    const shown = Math.min(Math.max(rule.freePosts, 0) + 2, 3);
    const posts = [];

    for (let number = 1; number <= shown; number++) {
      const free = number <= rule.freePosts;
      const gate: Gate | null = free
        ? rule.lock.length
          ? { mode: 'lock', reason }
          : null
        : { mode: rule.preview > 0 ? 'lead' : 'hide', reason, upgradeUrl: rule.upgradeUrl };

      posts.push(samplePost({ number, gate, locked: rule.lock, chars: rule.preview, compact: number > Math.max(rule.freePosts, 0) + 1 }));
    }

    if (rule.readers.length === 0) {
      return frame(t('admin.preview.title_nobody'), posts);
    }

    return frame(t(`admin.preview.title_${reason}`), posts);
  }
}
