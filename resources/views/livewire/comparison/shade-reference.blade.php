<section class="mt-12 max-w-3xl" aria-labelledby="shade-reference-h">
    <p class="label-mono m-0 mb-2">What the correction is judged against</p>
    <h2 id="shade-reference-h" class="m-0 font-display text-[26px] font-semibold">The shade reference</h2>
    <p class="mt-3 text-[15px] leading-relaxed text-ink-2">ČHMÚ Plzeň-Mikulka is about 3.5 km away. Even with no sun on the shield, the balcony and Mikulka differ by about half a degree on a typical morning, so Mikulka alone cannot tell a better correction from a local difference between the two places.</p>
    <p class="mt-3 text-[15px] leading-relaxed text-ink-2">The shield is in shade before the sun reaches it and again half an hour after the sun has left. At those times the sensor reads the balcony's air, and its difference from Mikulka is the local offset. The shade reference is Mikulka plus that offset:</p>
    <ul class="mt-3 list-disc space-y-2 pl-5 text-[15px] leading-relaxed text-ink-2">
        <li>The first anchor is the mean offset in the hour before the sun reaches the shield, from readings below {{ \App\ValueObject\Figure::format(\App\ValueObject\ShadeReference::SHADED_LUX, 0) }} lx.</li>
        <li>The second anchor is the mean offset 30 to 60 minutes after the sun has left the shield.</li>
        <li>From the moment the sun reaches the shield until an hour after it leaves, while the shield's stored heat fades, the offset runs in a straight line from the first anchor to the second. Mikulka still sets the shape of the morning.</li>
        <li>Outside that window the shade reference is Mikulka itself.</li>
        <li>A day without both anchors has no shade reference in that window. Its readings there are left out of calibration and validation.</li>
    </ul>
    <p class="mt-3 text-[15px] leading-relaxed text-ink-2">The correction is fitted to the shade reference, and a retrained model replaces the one in use only if it is not worse against it. On overcast mornings between 1 October and 5 November 2026, when the shield gains no heat and the right difference is zero, the shade reference was 0,21 °C from zero on average and Mikulka 0,52 °C.</p>
    <p class="mt-3 text-[14px] leading-relaxed text-ink-3">The straight line is an assumption: the local offset can change unevenly through a sunny morning, and the overcast test cannot check that. Agreement with the shade reference is evidence of a better shield correction, not a measurement of the remaining error on the balcony.</p>
</section>
