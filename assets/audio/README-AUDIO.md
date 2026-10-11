# Game audio: files and when they play

Eight sounds, all in this folder, all **MP3 with exactly these names** (lower-case). Replace a file and
reload the page; no code changes are needed. A missing or empty file is skipped silently, so you can add
them one at a time.

| File | Plays | Loops? |
|------|-------|--------|
| `welcome.mp3` | In the lobby / waiting room, until the game starts | **Yes** (seamless) |
| `present.mp3` | A new question appears ("question music") | No |
| `timer.mp3` | While the countdown runs (the ticking clock) | **Yes** (seamless) |
| `lock.mp3` | A player locks in an answer | No |
| `timeout.mp3` | The clock runs out (players who have not answered, and the host) | No |
| `suspense.mp3` | After the clock stops (or a player locks in), until it ends or the host reveals the answer | No |
| `correct.mp3` | Reveal: the answer was right | No |
| `wrong.mp3` | Reveal: the answer was wrong | No |

## The flow, per question
1. **Question appears:** `present`, with `timer` ticking quietly underneath it.
2. **Clock running:** `timer` loops. A player who locks in hears `lock`, then `suspense`.
3. **Clock stops** (time up, everyone answered, or the host closed it): `timer` stops. If time ran out, players
   who had not answered hear `timeout`; then everyone who is waiting hears `suspense`. The host's screen does the same.
4. **Host reveals:** `suspense` is cut and the verdict plays: each player hears `correct` or `wrong` for their
   own answer (nothing if they did not answer); the host's screen plays `correct` if at least one player got
   it right, otherwise `wrong`.

Sounds never pile up: a new one-off sound cuts the previous one, and `suspense` waits for `lock` / `timeout`
to finish before it starts.

## Editing tips
- **Trim silence** from the start of every file so the sound lands exactly on the event.
- **Loops** (`welcome`, `timer`): cut so the end flows into the start. For `timer`, cut between two ticks a whole
  number of beats apart, and use a few milliseconds of fade at both ends to avoid a click. Browsers may add a
  few milliseconds of gap when repeating an MP3, which is unnoticeable on a ticking beat.
- **Levels:** the game balances volumes in code. Keep the files at similar loudness (about -16 LUFS for
  one-off sounds, about -22 LUFS for `timer` and `suspense`) rather than normalising each to the maximum.
- **Size:** 96-128 kbps is plenty; keep the folder small so phones on mobile data load quickly.
- **Browsers need one tap** before they allow sound; the speaker button (bottom-right) mutes and unmutes.
- If phones and the host screen are in the same room, everything plays twice. Mute the screens that should be quiet.

## Licensing
The original TV show's music and effects are copyrighted. Clips from fan soundboards or ringtone sites do
not come with a licence. This is fine for a private party at home; before sharing the app publicly or using
it commercially, replace them with sounds you have the right to use. The audio files are therefore
**not committed to git** (see `.gitignore`); copy the folder to your host when you deploy.
