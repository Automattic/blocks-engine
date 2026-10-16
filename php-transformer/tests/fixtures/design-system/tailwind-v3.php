<?php
declare(strict_types=1);

// A dark product site built the way Tailwind CSS v3 compiles it, with the
// common shadcn-style theme: colour tokens are HSL channel triplets in custom
// properties, redefined under `.dark`, and read as `hsl(var(--token))`.
// Palette utilities use the v3 opacity variables
// (`rgb(15 23 42 / var(--tw-bg-opacity, 1))`), one accent is an `oklch()`
// arbitrary value, the dark theme is the `class` strategy on <html>, and
// responsive and hover variants are separate rules. Headings, paragraphs,
// buttons and sections get their colour and size only from utility classes.
//
// Font stacks: "IBM Plex Mono" is rendered under two different fallback
// stacks, "Inter Fallback" is only a @font-face fallback, and Georgia
// (`font-serif`) is declared but never rendered.
$css = '@font-face{font-family:"Inter Fallback";src:local("Arial");size-adjust:107%}'
    . '*,::before,::after{box-sizing:border-box;border-width:0;border-style:solid;border-color:#e5e7eb}::before,::after{--tw-content:\'\'}'
    . 'html,:host{line-height:1.5;-webkit-text-size-adjust:100%;tab-size:4;font-family:\'Inter\',\'Inter Fallback\',ui-sans-serif,system-ui,sans-serif;font-feature-settings:normal}'
    . 'body{margin:0;line-height:inherit}h1,h2,h3,h4,h5,h6{font-size:inherit;font-weight:inherit}a{color:inherit;text-decoration:inherit}'
    . 'button{font-family:inherit;font-size:100%;font-weight:inherit;line-height:inherit;color:inherit;margin:0;padding:0;background-color:transparent;background-image:none}'
    . 'blockquote,dl,dd,h1,h2,h3,h4,h5,h6,hr,figure,p,pre{margin:0}code,pre{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:1em}'
    . ':root{--background:0 0% 100%;--foreground:222.2 84% 4.9%;--muted:210 40% 96.1%;--muted-foreground:215.4 16.3% 46.9%;--primary:221.2 83.2% 53.3%;--primary-foreground:210 40% 98%}'
    . '.dark{--background:222 65% 6%;--foreground:216 100% 96%;--muted:222 47% 11%;--muted-foreground:219 33% 69%;--primary:217 91% 60%;--primary-foreground:222 65% 6%}'
    . '*,::before,::after{--tw-border-spacing-x:0;--tw-ring-offset-shadow:0 0 #0000;--tw-shadow:0 0 #0000}'
    . '.mx-auto{margin-left:auto;margin-right:auto}.max-w-6xl{max-width:72rem}'
    . '.bg-background{background-color:hsl(var(--background))}.bg-muted{background-color:hsl(var(--muted))}.bg-primary{background-color:hsl(var(--primary))}'
    . '.bg-white{--tw-bg-opacity:1;background-color:rgb(255 255 255 / var(--tw-bg-opacity, 1))}'
    . '.bg-\[\#0a1630\]{--tw-bg-opacity:1;background-color:rgb(10 22 48 / var(--tw-bg-opacity, 1))}'
    . '.bg-\[oklch\(0\.77_0\.15_163\)\]{background-color:oklch(0.77 0.15 163)}'
    . '.text-foreground{color:hsl(var(--foreground))}.text-muted-foreground{color:hsl(var(--muted-foreground))}.text-primary{color:hsl(var(--primary))}'
    . '.text-primary-foreground{color:hsl(var(--primary-foreground))}.text-slate-900{--tw-text-opacity:1;color:rgb(15 23 42 / var(--tw-text-opacity, 1))}'
    . '.text-xs{font-size:0.75rem;line-height:1rem}.text-sm{font-size:0.875rem;line-height:1.25rem}.text-base{font-size:1rem;line-height:1.5rem}'
    . '.text-lg{font-size:1.125rem;line-height:1.75rem}.text-2xl{font-size:1.5rem;line-height:2rem}.text-4xl{font-size:2.25rem;line-height:2.5rem}'
    . '.font-display{font-family:"Space Grotesk",\'Inter\',sans-serif}.font-mono{font-family:"IBM Plex Mono",ui-monospace,monospace}'
    . '.font-code{font-family:\'IBM Plex Mono\',Menlo,monospace}.font-serif{font-family:Georgia,Cambria,"Times New Roman",serif}'
    . '.font-semibold{font-weight:600}.font-bold{font-weight:700}.tracking-tight{letter-spacing:-0.025em}'
    . '.p-4{padding:1rem}.p-6{padding:1.5rem}.px-4{padding-left:1rem;padding-right:1rem}.px-5{padding-left:1.25rem;padding-right:1.25rem}'
    . '.py-2{padding-top:0.5rem;padding-bottom:0.5rem}.py-12{padding-top:3rem;padding-bottom:3rem}.mt-4{margin-top:1rem}.mt-8{margin-top:2rem}'
    . '.rounded-lg{border-radius:0.5rem}'
    . '.hover\:bg-primary\/90:hover{background-color:hsl(var(--primary) / 0.9)}.hover\:text-foreground:hover{color:hsl(var(--foreground))}'
    . '.dark\:bg-white\/5:where(.dark, .dark *){background-color:rgb(255 255 255 / 0.05)}'
    . '@media (min-width:768px){.md\:py-24{padding-top:6rem;padding-bottom:6rem}.md\:text-5xl{font-size:3rem;line-height:1}.md\:text-lg{font-size:1.125rem;line-height:1.75rem}}';
$page = static function (string $title, string $lede, string $body, string $prefix): string {
    return '<!doctype html><html lang="en" class="dark"><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="' . $prefix . 'build/tailwind.css"></head>'
        . '<body class="bg-background text-foreground">'
        . '<header class="bg-[#0a1630] px-4 py-2"><p class="text-lg font-bold font-display text-foreground">Lumen</p></header>'
        . '<main class="max-w-6xl mx-auto px-4 py-12 md:py-24">'
        . '<h1 class="text-4xl md:text-5xl font-bold tracking-tight font-display text-foreground">' . $title . '</h1>'
        . '<p class="mt-4 text-lg text-muted-foreground">' . $lede . '</p>'
        . '<section class="mt-8 bg-muted p-6 rounded-lg"><h2 class="text-2xl font-semibold font-display text-foreground">How it works</h2>'
        . '<p class="mt-4 text-base text-muted-foreground">' . $body . '</p>'
        . '<p class="mt-4 text-base text-foreground">' . $body . ' It keeps working while you sleep.</p>'
        . '<p class="mt-4 text-sm font-mono text-primary">npm install lumen</p></section>'
        . '<section class="mt-8 bg-primary p-6 rounded-lg"><h2 class="text-2xl font-semibold font-display text-primary-foreground">Start in minutes</h2>'
        . '<p class="mt-4 text-base text-primary-foreground">Connect a repository and the first report is ready before your coffee is.</p></section>'
        . '<p class="mt-8 text-base text-muted-foreground md:text-lg">Questions? <a class="text-primary hover:text-foreground" href="' . $prefix . 'about/index.html">Talk to the team</a> any time.</p>'
        . '<p class="mt-4 text-xs font-code text-muted-foreground">v2.4.1 build 2026.10</p>'
        . '<button class="mt-4 bg-primary hover:bg-primary/90 text-primary-foreground text-sm font-semibold px-5 py-2 rounded-lg" type="button">Get started</button>'
        . '<p class="mt-4 bg-[oklch(0.77_0.15_163)] text-slate-900 text-sm p-4 rounded-lg">New: weekly digests are here.</p>'
        . '</main>'
        . '<footer class="bg-[#0a1630] p-6"><p class="text-sm text-muted-foreground">Lumen Labs. Built in the open.</p></footer>'
        . '</body></html>';
};
return array(
    'entrypoint' => 'index.html',
    'files' => array(
        'build/tailwind.css' => $css,
        'index.html' => $page('Observability without the noise', 'Lumen watches your services and tells you only what changed and why it matters.', 'Every deploy is compared with the last one, so regressions surface in minutes instead of days.', ''),
        'about/index.html' => $page('About Lumen', 'We are a small team of operators who were tired of dashboards nobody reads.', 'We started Lumen after one too many incidents that a single sentence could have prevented.', '../'),
        'docs/index.html' => $page('Documentation', 'Install the agent, connect a repository and read your first report.', 'The agent is a single binary with no dependencies, and it sends only the metadata you allow.', '../'),
    ),
);
