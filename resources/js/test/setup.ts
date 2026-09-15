/**
 * Shared set-up for every frontend test (P0-56, FD-3).
 *
 * React Testing Library renders a component the way a person meets it — by
 * role, label and text — and user-event drives it the way they would. Two
 * things have to hold for every test file, so they live here once:
 *
 *   - **jest-dom matchers** (`toBeInTheDocument`, `toHaveAccessibleName`, …)
 *     registered on Vitest's `expect`;
 *   - **cleanup after each test**, so one test's rendered tree can never be
 *     found by the next. Testing Library only does this by itself when test
 *     globals are switched on, and this project imports them explicitly.
 *
 * Each test file still names its own environment (`@vitest-environment jsdom`),
 * as the existing tests do.
 */
import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

afterEach(() => {
    cleanup();
});
