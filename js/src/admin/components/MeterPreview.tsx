import Component, { type ComponentAttrs } from 'flarum/common/Component';
import type Stream from 'flarum/common/utils/Stream';
import { t } from '../../common/t';
import type { MeterInfo } from '../../common/types';
import { frame, samplePost } from './Sample';

interface Attrs extends ComponentAttrs {
  limit: Stream<string>;
  period: Stream<string>;
  leadIn: Stream<string>;
}

/** What a guest who has used up their free discussions sees, live. */
export default class MeterPreview extends Component<Attrs> {
  view() {
    const limit = Math.max(1, parseInt(this.attrs.limit(), 10) || 5);
    const leadIn = Math.max(0, parseInt(this.attrs.leadIn(), 10) || 0);
    const period = (['day', 'week', 'month'].includes(this.attrs.period()) ? this.attrs.period() : 'month') as MeterInfo['period'];
    const meter: MeterInfo = { limit, remaining: 0, counted: false, warn: 0, period };

    return (
      <div className="Form-group">
        {frame(t('admin.preview.title_meter'), [
          samplePost({ number: 1, gate: { mode: leadIn > 0 ? 'lead' : 'hide', reason: 'meter' }, locked: [], chars: leadIn, meter }),
          samplePost({ number: 2, gate: { mode: 'hide', reason: 'meter' }, locked: [] }),
        ])}
      </div>
    );
  }
}
