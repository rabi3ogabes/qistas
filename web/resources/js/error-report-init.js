// Starts the website's error reporter (resources/js/error-report.js) on the page's window the moment this file is imported.
//
// app.js imports it FIRST. A module is evaluated in the order its imports are written, and all of them before the importing
// file's own lines, so a call written in app.js would only start after every other module had been evaluated: an error
// thrown while one of those was being evaluated would go unreported. This file has no code but the call, and nothing to export.
import { initErrorReporting } from './error-report.js';

initErrorReporting(typeof window === 'undefined' ? undefined : window);
