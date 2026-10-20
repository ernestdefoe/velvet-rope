import app from 'flarum/admin/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import FormGroup from 'flarum/common/components/FormGroup';
import Icon from 'flarum/common/components/Icon';
import Stream from 'flarum/common/utils/Stream';
import type Group from 'flarum/common/models/Group';
import RulePreview from './RulePreview';
import { t } from '../../common/t';
import type { LockKind } from '../../common/types';

const a = (k: string, p?: any) => t('admin.rules.' + k, p);

export interface Rule {
  tags: number[];
  readers: number[];
  freePosts: number;
  preview: number;
  lock: LockKind[];
  upgradeUrl?: string;
}

const KINDS: LockKind[] = ['image', 'file', 'embed'];
const GUEST = '2';
const ADMIN = '1';
const MEMBER = 3;

/** A key per card that survives reordering, so each keeps its own tag picker. */
const keys = new WeakMap<Rule, number>();
let nextKey = 0;
const keyOf = (rule: Rule) => keys.get(rule) ?? (keys.set(rule, ++nextKey), nextKey);

const blank = (): Rule => ({ tags: [], readers: [MEMBER], freePosts: 1, preview: 0, lock: [] });

/**
 * The rules, as cards in the order they apply: a discussion takes the first
 * rule naming one of its tags, or naming none. Drag a card by its handle to
 * reorder. Edits go straight into the setting's stream, so core's Save button
 * saves them with everything else.
 */
export default class RulesEditor extends Component<ComponentAttrs & { setting: Stream<string> }> {
  rules: Rule[] = [];
  tagStreams: Stream<string>[] = [];
  dragging: number | null = null;

  oninit(vnode: any) {
    super.oninit(vnode);

    try {
      const parsed = JSON.parse(this.attrs.setting() || '[]');
      this.rules = Array.isArray(parsed) ? parsed.map((r: any) => ({ ...blank(), ...r })) : [];
    } catch {
      this.rules = [];
    }

    this.tagStreams = this.rules.map((rule) => this.tagStream(rule));
  }

  view() {
    return (
      <div className="GuestGate-rules">
        {this.rules.length === 0 && <p className="GuestGate-rules-empty">{a('empty')}</p>}
        {this.rules.map((rule, i) => this.card(rule, i))}
        <Button className="Button" icon="fas fa-plus" onclick={() => this.add()}>
          {a('add')}
        </Button>
      </div>
    );
  }

  card(rule: Rule, i: number) {
    const tagsOn = !!app.data.extensions['flarum-tags'];
    const groups = app.store.all<Group>('groups').filter((g) => g.id() !== GUEST && g.id() !== ADMIN);

    return (
      <div
        key={keyOf(rule)}
        className={`GuestGate-rule ${this.dragging === i ? 'is-dragging' : ''}`}
        ondragover={(e: DragEvent) => e.preventDefault()}
        ondrop={(e: DragEvent) => {
          e.preventDefault();
          this.move(this.dragging, i);
        }}
      >
        <div className="GuestGate-rule-head">
          <span
            className="GuestGate-rule-grip"
            draggable="true"
            title={a('drag')}
            ondragstart={(e: DragEvent) => {
              this.dragging = i;
              e.dataTransfer!.effectAllowed = 'move';
              e.dataTransfer!.setData('text/plain', String(i));
            }}
            ondragend={() => (this.dragging = null)}
          >
            <Icon name="fas fa-grip-vertical" />
          </span>
          <b>{a('title', { number: i + 1 })}</b>
          <Button className="Button Button--icon Button--link" icon="far fa-trash-alt" aria-label={a('delete')} onclick={() => this.remove(i)} />
        </div>

        <div className="GuestGate-rule-grid">
          <div className="GuestGate-rule-fields">
            {tagsOn ? <FormGroup type="flarum-tags.select-tags" label={a('tags')} help={a('tags_help')} stream={this.tagStreams[i]} /> : null}

            <div className="Form-group">
              <label>{a('readers')}</label>
              <p className="helpText">{a('readers_help')}</p>
              <div className="GuestGate-checks">
                {groups.map((group) =>
                  this.check(rule.readers.includes(Number(group.id())), group.namePlural(), (on) =>
                    this.toggle(rule, 'readers', Number(group.id()), on)
                  )
                )}
              </div>
            </div>

            <div className="GuestGate-rule-numbers">
              {this.number(rule, 'freePosts', 0, 50)}
              {this.number(rule, 'preview', 0, 2000)}
            </div>

            <div className="Form-group">
              <label>{a('lock')}</label>
              <p className="helpText">{a('lock_help')}</p>
              <div className="GuestGate-checks">
                {KINDS.map((kind) => this.check(rule.lock.includes(kind), a(`lock_${kind}`), (on) => this.toggle(rule, 'lock', kind, on)))}
              </div>
            </div>

            <div className="Form-group">
              <label>{a('upgrade')}</label>
              <p className="helpText">{a('upgrade_help')}</p>
              <input
                className="FormControl"
                type="url"
                placeholder="https://"
                value={rule.upgradeUrl || ''}
                oninput={(e: InputEvent) => this.change(() => (rule.upgradeUrl = (e.target as HTMLInputElement).value.trim() || undefined))}
              />
            </div>
          </div>

          <RulePreview rule={rule} />
        </div>
      </div>
    );
  }

  check(on: boolean, label: any, set: (on: boolean) => void) {
    return (
      <label className="checkbox">
        <input type="checkbox" checked={on} onchange={(e: Event) => set((e.target as HTMLInputElement).checked)} />
        {label}
      </label>
    );
  }

  number(rule: Rule, key: 'freePosts' | 'preview', min: number, max: number) {
    return (
      <div className="Form-group">
        <label>{a(key)}</label>
        <p className="helpText">{a(`${key}_help`)}</p>
        <input
          className="FormControl"
          type="number"
          min={min}
          max={max}
          value={rule[key]}
          oninput={(e: InputEvent) =>
            this.change(() => (rule[key] = Math.max(min, Math.min(max, parseInt((e.target as HTMLInputElement).value, 10) || 0))))
          }
        />
      </div>
    );
  }

  toggle<K extends 'readers' | 'lock'>(rule: Rule, key: K, value: Rule[K][number], on: boolean) {
    this.change(() => {
      const list = (rule[key] as any[]).filter((v) => v !== value);
      rule[key] = (on ? [...list, value] : list) as Rule[K];
    });
  }

  /** A stream for core's tag picker, written back into the rule. */
  tagStream(rule: Rule): Stream<string> {
    const stream = Stream(JSON.stringify(rule.tags.map(String)));
    stream.map((json: string) => {
      const tags = (JSON.parse(json || '[]') as string[]).map(Number);
      if (tags.join() !== rule.tags.join()) this.change(() => (rule.tags = tags));
    });

    return stream;
  }

  add() {
    const rule = blank();
    this.rules.push(rule);
    this.tagStreams.push(this.tagStream(rule));
    this.save();
  }

  remove(i: number) {
    this.rules.splice(i, 1);
    this.tagStreams.splice(i, 1);
    this.save();
  }

  move(from: number | null, to: number) {
    this.dragging = null;
    if (from === null || from === to) return;

    const [rule] = this.rules.splice(from, 1);
    const [stream] = this.tagStreams.splice(from, 1);
    this.rules.splice(to, 0, rule);
    this.tagStreams.splice(to, 0, stream);
    this.save();
  }

  change(edit: () => void) {
    edit();
    this.save();
  }

  save() {
    this.attrs.setting(
      JSON.stringify(
        this.rules.map(({ tags, readers, freePosts, preview, lock, upgradeUrl }) => ({
          tags,
          readers,
          freePosts,
          preview,
          lock,
          ...(upgradeUrl ? { upgradeUrl } : {}),
        }))
      )
    );
  }
}
