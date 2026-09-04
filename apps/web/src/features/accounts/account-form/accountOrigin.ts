import type { AccountKind, Product, ProductCapability, ProductModel } from '@cadran/api-client';

/**
 * What backs a new account: a system catalogue product, a reusable template
 * of the calling workspace, or nothing. The wizard and the form share one
 * shape so a kind lock, a valuation-mode filter and the submitted body never
 * have to ask which concrete type they were handed.
 */
export type AccountOrigin =
  { type: 'product'; product: Product } | { type: 'template'; template: ProductModel } | null;

export function originKind(origin: AccountOrigin): AccountKind | null {
  if (origin === null) {
    return null;
  }

  return origin.type === 'product' ? origin.product.accountKind : origin.template.family;
}

export function originCapabilities(origin: AccountOrigin): ProductCapability[] {
  if (origin === null) {
    return [];
  }

  return origin.type === 'product' ? origin.product.capabilities : origin.template.capabilities;
}

/** The catalogue reference to submit, or null when the origin is not a product. */
export function originProductCode(origin: AccountOrigin): string | null {
  return origin?.type === 'product' ? origin.product.code : null;
}

/** The template reference to submit, or null when the origin is not a template. */
export function originProductModelId(origin: AccountOrigin): string | null {
  return origin?.type === 'template' ? origin.template.id : null;
}

export function sameOrigin(a: AccountOrigin, b: AccountOrigin): boolean {
  if (a === null || b === null) {
    return a === b;
  }
  if (a.type === 'product' && b.type === 'product') {
    return a.product.code === b.product.code;
  }

  return a.type === 'template' && b.type === 'template' && a.template.id === b.template.id;
}
