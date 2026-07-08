/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Inline Material Design Icons (same set @nextcloud/vue uses).
 */

const svg = (path: string): string =>
	`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="${path}"/></svg>`

/** mdi-lock — sidebar tab and status badge. */
export const lockSvg = svg(
	'M12,17A2,2 0 0,0 14,15C14,13.89 13.1,13 12,13A2,2 0 0,0 10,15A2,2 0 0,0 12,17M18,8A2,2 0 0,1 20,10V20A2,2 0 0,1 18,22H6A2,2 0 0,1 4,20V10C4,8.89 4.9,8 6,8H7V6A5,5 0 0,1 12,1A5,5 0 0,1 17,6V8H18M12,3A3,3 0 0,0 9,6V8H15V6A3,3 0 0,0 12,3Z',
)

/** mdi-lock-plus — protect action. */
export const lockPlusSvg = svg(
	'M18 8H17V6C17 3.24 14.76 1 12 1S7 3.24 7 6V8H6C4.9 8 4 8.9 4 10V20C4 21.1 4.9 22 6 22H13.26C12.47 20.87 12 19.5 12 18C12 14.69 14.69 12 18 12C18.7 12 19.37 12.12 20 12.34V10C20 8.9 19.1 8 18 8M9 6C9 4.34 10.34 3 12 3S15 4.34 15 6V8H9V6M21 17H19V15H17V17H15V19H17V21H19V19H21V17Z',
)

/** mdi-lock-open-variant — unprotect action. */
export const lockOpenSvg = svg(
	'M18,1C15.24,1 13,3.24 13,6V8H4A2,2 0 0,0 2,10V20A2,2 0 0,0 4,22H16A2,2 0 0,0 18,20V10A2,2 0 0,0 16,8H15V6A3,3 0 0,1 18,3A3,3 0 0,1 21,6V8H23V6C23,3.24 20.76,1 18,1M10,13A2,2 0 0,1 12,15A2,2 0 0,1 10,17A2,2 0 0,1 8,15A2,2 0 0,1 10,13Z',
)

/** mdi-restart — retry action. */
export const restartSvg = svg(
	'M12,4C14.1,4 16.1,4.8 17.6,6.3C20.7,9.4 20.7,14.5 17.6,17.6C15.8,19.5 13.3,20.2 10.9,19.9L11.4,17.9C13.1,18.1 14.9,17.5 16.2,16.2C18.5,13.9 18.5,10.1 16.2,7.7C15.1,6.6 13.5,6 12,6V10.6L7,5.6L12,0.6V4M6.3,17.6C3.7,15 3.3,11 5.1,7.9L6.6,9.4C5.5,11.6 5.9,14.4 7.8,16.2C8.3,16.7 8.9,17.1 9.6,17.4L9,19.4C8,19 7.1,18.4 6.3,17.6Z',
)
