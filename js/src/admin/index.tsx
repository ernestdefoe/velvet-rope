import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import RulesEditor from './components/RulesEditor';
import MeterPreview from './components/MeterPreview';
import { t } from '../common/t';

const P = 'ernestdefoe-guest-gate.';
const a = (k: string, p?: any) => t('admin.' + k, p);

/**
 * Everything is in core's settings form, so it saves with one button: the
 * meter, search engines, and the rules (one JSON setting, edited by
 * RulesEditor). Declared through the `extend` export: in Flarum 2 an admin
 * initializer runs before `app.extensionData` exists.
 */
export const extend = [
  new Extend.Admin()
    .customSetting(function (this: any) {
      return (
        <div className="Form-group GuestGate-intro">
          <h3>{a('rules.heading')}</h3>
          <p className="helpText">{a('rules.help')}</p>
          <RulesEditor setting={this.setting(P + 'rules')} />
        </div>
      );
    }, 100)
    .customSetting(() => <h3 className="GuestGate-heading">{a('meter.heading')}</h3>, 90)
    .setting(() => ({ setting: P + 'meter_enabled', type: 'boolean', label: a('meter.enabled'), help: a('meter.enabled_help') }), 89)
    .setting(() => ({ setting: P + 'meter_limit', type: 'number', min: 1, max: 100, label: a('meter.limit') }), 88)
    .setting(
      () => ({
        setting: P + 'meter_period',
        type: 'select',
        options: { day: a('meter.period_day'), week: a('meter.period_week'), month: a('meter.period_month') },
        default: 'month',
        label: a('meter.period'),
        help: a('meter.period_help'),
      }),
      87
    )
    .setting(() => ({ setting: P + 'meter_warn', type: 'number', min: 0, max: 100, label: a('meter.warn'), help: a('meter.warn_help') }), 86)
    .setting(
      () => ({ setting: P + 'meter_lead_in', type: 'number', min: 0, max: 2000, label: a('meter.lead_in'), help: a('meter.lead_in_help') }),
      85
    )
    .setting(
      () =>
        app.data.extensions['flarum-tags']
          ? { setting: P + 'meter_exempt_tags', type: 'flarum-tags.select-tags', label: a('meter.exempt'), help: a('meter.exempt_help') }
          : null,
      84
    )
    .customSetting(function (this: any) {
      return (
        <MeterPreview limit={this.setting(P + 'meter_limit')} period={this.setting(P + 'meter_period')} leadIn={this.setting(P + 'meter_lead_in')} />
      );
    }, 83)
    .customSetting(() => <h3 className="GuestGate-heading">{a('crawlers.heading')}</h3>, 70)
    .setting(() => ({ setting: P + 'crawlers', type: 'boolean', label: a('crawlers.enabled'), help: a('crawlers.help') }), 69),
];

app.initializers.add('ernestdefoe-guest-gate', () => {});
