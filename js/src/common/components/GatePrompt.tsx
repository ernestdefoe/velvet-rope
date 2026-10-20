import app from 'flarum/common/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import classList from 'flarum/common/utils/classList';
import { t } from '../t';
import type { Gate, MeterInfo } from '../types';

export type Action = 'signUp' | 'logIn' | 'resend' | 'upgrade';

export interface GatePromptAttrs extends ComponentAttrs {
  gate: Gate;
  meter?: MeterInfo | null;
  /** One line and the buttons: under each hidden reply. */
  compact?: boolean;
  /** What a button does; without it (the admin preview) buttons do nothing. */
  onaction?: (what: Action, gate: Gate) => void;
  /** Show the sign-up button whatever the forum's setting (the preview). */
  preview?: boolean;
}

/**
 * The prompt where gated content would be: sign up or log in (guests), confirm
 * your email (unconfirmed accounts), or upgrade (members outside a rule's
 * groups). Shared by the forum and the admin preview, so the preview is the
 * real thing.
 */
export default class GatePrompt extends Component<GatePromptAttrs> {
  view() {
    const { compact } = this.attrs;
    const reason = this.attrs.gate.reason;

    return (
      <div className={classList('VelvetRope-prompt', `VelvetRope-prompt--${reason}`, compact && 'VelvetRope-prompt--compact')} role="note">
        <Icon name={reason === 'member' ? 'fas fa-crown' : 'fas fa-lock'} className="VelvetRope-prompt-icon" />
        <div className="VelvetRope-prompt-body">
          <h4 className="VelvetRope-prompt-title">{this.title()}</h4>
          {!compact && <p className="VelvetRope-prompt-text">{t(`lib.prompt.${reason}_text`, { email: app.session?.user?.email?.() || '' })}</p>}
        </div>
        <div className="VelvetRope-prompt-actions">{this.buttons()}</div>
      </div>
    );
  }

  title() {
    const { gate, meter, compact } = this.attrs;

    if (gate.reason === 'meter' && meter && !compact) {
      return t(`lib.prompt.meter_title_${meter.period}`, { count: meter.limit });
    }

    return t(`lib.prompt.${gate.reason}_title${compact ? '_short' : ''}`);
  }

  buttons() {
    const { gate, onaction, preview } = this.attrs;
    const run = (what: Action) => () => onaction?.(what, gate);

    if (gate.reason === 'confirm') {
      return (
        <Button className="Button Button--primary" onclick={run('resend')}>
          {t('lib.prompt.resend')}
        </Button>
      );
    }

    if (gate.reason === 'member') {
      return gate.upgradeUrl ? (
        <Button className="Button Button--primary" icon="fas fa-arrow-up" onclick={run('upgrade')}>
          {t('lib.prompt.upgrade')}
        </Button>
      ) : null;
    }

    return [
      (preview || app.forum.attribute('allowSignUp')) && (
        <Button className="Button Button--primary" onclick={run('signUp')}>
          {t('lib.prompt.sign_up')}
        </Button>
      ),
      <Button className="Button" onclick={run('logIn')}>
        {t('lib.prompt.log_in')}
      </Button>,
    ];
  }
}
