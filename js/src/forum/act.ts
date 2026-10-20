import app from 'flarum/common/app';
import { t } from '../common/t';
import type { Gate } from '../common/types';
import type { Action } from '../common/components/GatePrompt';

/** What a prompt's button, or a click on locked media, does. */
export function act(what: Action, gate: Gate): void {
  if (what === 'upgrade') {
    if (gate.upgradeUrl) window.location.href = gate.upgradeUrl;

    return;
  }

  if (what === 'resend') {
    const user = app.session.user;
    if (!user) return;

    app
      .request({ method: 'POST', url: app.forum.attribute('apiUrl') + '/users/' + user.id() + '/send-confirmation' })
      .then(() => app.alerts.show({ type: 'success' }, t('lib.prompt.resent')));

    return;
  }

  if (what === 'signUp' && app.forum.attribute('allowSignUp')) {
    app.modal.show(() => import('flarum/forum/components/SignUpModal'));
  } else {
    app.modal.show(() => import('flarum/forum/components/LogInModal'));
  }
}

/** The action a lock's click takes for a reader of this kind. */
export function primaryAction(gate: Gate): Action {
  if (gate.reason === 'confirm') return 'resend';
  if (gate.reason === 'member') return 'upgrade';

  return app.forum.attribute('allowSignUp') ? 'signUp' : 'logIn';
}
