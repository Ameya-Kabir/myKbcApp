# Game audio: what to download and what to name it

The game ships with **empty placeholder files** in this folder, so it runs silently. To get the
classic quiz-show atmosphere, download your own sounds and **replace the files below, keeping the
exact names** (lower-case, `.mp3`). No code changes are needed. Refresh the page after replacing.

| File name | When it plays | What to look for | Loops? | Suggested length |
|-----------|---------------|------------------|--------|------------------|
| `welcome.mp3` | Lobby / waiting room, until the game starts | Calm, inviting intro or "waiting" music | **Yes** (seamless) | 30-90 s |
| `present.mp3` | A new question appears | Short question-reveal sting / swoosh | No | 1-4 s |
| `timer.mp3` | While the countdown runs (stops when you lock in, time runs out or the answer is revealed) | Suspenseful tick-tock or heartbeat bed | **Yes** (seamless) | 5-30 s |
| `lock.mp3` | A player locks in an answer ("Final answer") | Short, decisive lock-in chime or thump | No | 1-3 s |
| `timeout.mp3` | The timer hits 0 | Buzzer / "time's up" sting | No | 1-3 s |
| `correct.mp3` | The host reveals the answer and it is **right** | Cheerful win fanfare | No | 2-5 s |
| `wrong.mp3` | The host reveals the answer and it is **wrong** | Low, sad "wrong answer" sting | No | 2-5 s |

## Tips
- **Format:** MP3 only. 128 kbps is plenty. Mono or stereo is fine.
- **Size:** keep the whole folder under a few MB so phones on mobile data load quickly. Trim silence from
  the start of each file so the sound lands exactly on the event.
- **Seamless loops:** `welcome.mp3` and `timer.mp3` repeat. Pick tracks that end where they begin, or
  trim them in a free editor (Audacity) so there is no gap or click at the loop point.
- **Volume:** the game already balances levels (the lobby music is quieter, the timer sits under other
  effects). If one file is much louder or quieter than the rest, normalise it in an editor rather than
  changing code.
- **Missing or empty files are skipped silently.** You can add them one at a time.
- **Licensing:** the original TV show's music and effects are copyrighted. For anything beyond private use
  at home, use royalty-free or properly licensed sound effects (for example from a stock-audio site, or
  Creative Commons / public-domain libraries) and check the licence terms.

## How the sounds behave
- Sounds never pile up: a new effect cuts the previous one, and the timer ticking dips in volume under the
  `present` sting.
- **Players'** screens play `lock.mp3` the moment they answer and play `correct.mp3` or `wrong.mp3` for
  their own result when the host reveals it. A player who did not answer hears `timeout.mp3` when the clock
  runs out, then nothing at the reveal.
- The **host's** screen plays `timeout.mp3` when time runs out, and at the reveal plays `correct.mp3` if at
  least one player got it right, otherwise `wrong.mp3`.
- Browsers block sound until someone taps the page. The first tap anywhere switches audio on. The round
  speaker button in the bottom-right corner mutes and unmutes (the choice is remembered per browser).
- If phones and the host screen are in the same room, expect everything to sound twice. Tap the speaker
  button on the screens that should stay quiet.
