export const MODAL_INTENTS = [
  'create',
  'edit',
  'delete',
  'archive',
  'confirm',
  'detail',
  'settings',
] as const;

export type ModalIntent = (typeof MODAL_INTENTS)[number];
