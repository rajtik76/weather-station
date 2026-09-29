# Sky backgrounds

The photographs behind the reading now on the dashboard's sky. They were
generated for this page with OpenAI's image model (September 2026) from the
prompts below; under OpenAI's terms the output belongs to the person who made
it, so they carry no third-party licence.

`Dashboard::skyScene()` picks one as `{condition}-{day|night}`: the rain the
microphone hears now wins, then the next forecast hour's rain chance, day or
night by the real sunrise. The station knows no cloud cover, so the `overcast`
pair is here for when it does and is not shown yet.

| File              | Width   | Use                        |
| ----------------- | ------- | -------------------------- |
| `<name>.webp`     | 1536 px | wide screens               |
| `<name>-768.webp` | 768 px  | phones, picked by `srcset` |

## Composition

The sky widget is about 3:1 and crops the 3:2 picture to its middle band, so
everything that matters sits between 25 % and 75 % of the height. The sun or
moon sits at about 70 % of the width: the left of the widget carries the big
reading under a darker scrim, and a phone's crop keeps the right side.

## Prompts

The base, the same for every picture so the set stays one style:

```
Photorealistic wide sky photograph for a website background, 1536x1024.
Only sky: no ground, no horizon line, no hills, trees, buildings, people, birds, aircraft, text or logos.
Calm, soft and uncluttered, with smooth gradients and little fine detail so it compresses well.
Composition: the main subject sits in the right third of the frame, at about 70% from the left and at mid height.
The left 40% of the frame is plain sky with no strong features, because large white text will go over it.
Keep everything important within the middle band of the image (between 25% and 75% of its height),
because the top and bottom will be cropped away.
Natural colours, gentle contrast, no lens dirt, no vignette, no HDR look.
```

followed by one line per picture:

| Name             | Line                                                                                                                                                  |
| ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| `clear-day`      | Clear, cloudless deep blue daytime sky, bright sun disc with a soft glow at the right, lighter blue towards the lower part of the frame.              |
| `clear-night`    | Clear night sky, deep navy to near-black, a crisp crescent moon at the right with a faint glow, a few scattered small stars, no Milky Way.            |
| `partly-day`     | Blue daytime sky with a few soft white cumulus clouds, the sun partly visible beside one cloud at the right, plenty of open blue on the left.         |
| `partly-night`   | Night sky, dark navy, the moon at the right partly behind a thin moonlit cloud, a few scattered clouds, calm.                                         |
| `overcast-day`   | Fully overcast daytime sky, layered soft grey clouds with gentle texture and slightly lighter areas where daylight diffuses, no blue sky, not stormy. |
| `overcast-night` | Fully overcast night sky, dark grey-blue cloud layers faintly lit from below, no moon or stars visible, calm.                                         |
| `drizzle-day`    | Grey overcast daytime sky with a fine, light drizzle visible as thin faint streaks in the air, soft and misty, not stormy.                            |
| `drizzle-night`  | Dark overcast night sky with a fine, light drizzle visible as thin faint streaks catching a little light, misty and calm.                             |
| `rain-day`       | Dark grey rainy daytime sky, heavy low clouds, clearly visible rain streaks falling slightly diagonally, moody but not a thunderstorm.                |
| `rain-night`     | Very dark rainy night sky, low clouds, rain streaks faintly visible against a dim glow, moody and calm, no lightning.                                 |

## Encoding

From the 1536x1024 PNG, with Pillow: WebP at quality 70, method 6, and 58 for
the two rain pictures, whose streaks otherwise double the file. The 768 px
copy is the same picture resized with Lanczos.

```python
from PIL import Image

image = Image.open("clear-day.png").convert("RGB")
image.save("clear-day.webp", "WEBP", quality=70, method=6)
image.resize((768, 512), Image.LANCZOS).save("clear-day-768.webp", "WEBP", quality=70, method=6)
```
