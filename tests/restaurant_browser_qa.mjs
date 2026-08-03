// Compatibility entrypoint: the old local-demo fixture was removed during the
// server-authoritative cutover. Keep the historical command pointed at the
// unified browser QA so it cannot report a false failure from SavoraState.
import './task29_browser_qa.mjs';
