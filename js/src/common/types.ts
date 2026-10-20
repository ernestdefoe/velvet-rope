/** A post's `guestGate` attribute: what this reader was not sent, and why. */
export interface Gate {
  mode: 'lock' | 'lead' | 'hide';
  reason: 'guest' | 'meter' | 'confirm' | 'member';
  upgradeUrl?: string;
  /** Rendered for no visitor in particular (a realtime push): fetch it again. */
  generic?: boolean;
}

/** A discussion's `guestGateMeter` attribute, for metered guests. */
export interface MeterInfo {
  limit: number;
  remaining: number;
  counted: boolean;
  warn: number;
  period: 'day' | 'week' | 'month';
}

export type LockKind = 'image' | 'file' | 'embed';
