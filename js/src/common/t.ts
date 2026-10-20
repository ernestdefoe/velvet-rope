import app from 'flarum/common/app';

/** This extension's strings, by the part of the key after the extension id. */
export const t = (k: string, p?: any): any => app.translator.trans('ernestdefoe-guest-gate.' + k, p);
