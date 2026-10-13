import { Component, type ReactNode } from 'react';

interface RouteErrorBoundaryProps {
  children: ReactNode;
  /** Shown in place of the page when it fails to load or render. */
  fallback: ReactNode;
  /** A new key (the path) gives the page another chance. */
  resetKey: string;
}

interface RouteErrorBoundaryState {
  failed: boolean;
  resetKey: string;
}

/**
 * Catches a page whose chunk could not be fetched (a deployment replaced it, or
 * the network dropped) so the shell stays usable. Navigating elsewhere clears it.
 */
export class RouteErrorBoundary extends Component<
  RouteErrorBoundaryProps,
  RouteErrorBoundaryState
> {
  state: RouteErrorBoundaryState = { failed: false, resetKey: this.props.resetKey };

  static getDerivedStateFromProps(
    props: RouteErrorBoundaryProps,
    state: RouteErrorBoundaryState,
  ): RouteErrorBoundaryState | null {
    return props.resetKey === state.resetKey ? null : { failed: false, resetKey: props.resetKey };
  }

  static getDerivedStateFromError(): Partial<RouteErrorBoundaryState> {
    return { failed: true };
  }

  render() {
    return this.state.failed ? this.props.fallback : this.props.children;
  }
}
