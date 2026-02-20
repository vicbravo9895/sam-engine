import AppLogoIcon from '@/components/app-logo-icon';
import { FadeIn, SlideUp } from '@/components/motion';
import { home } from '@/routes';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { type PropsWithChildren, useRef, useState } from 'react';

const DEFAULT_HERO_VIDEO = '/videos/login-hero.mp4';

interface AuthLayoutProps {
    title?: string;
    description?: string;
    heroVideoSrc?: string | null;
}

export default function AuthSplitLayout({
    children,
    title,
    description,
    heroVideoSrc,
}: PropsWithChildren<AuthLayoutProps>) {
    const { name } = usePage<SharedData>().props;
    const videoRef = useRef<HTMLVideoElement>(null);
    const [videoReady, setVideoReady] = useState(false);
    const useVideo = heroVideoSrc !== null && (heroVideoSrc ?? DEFAULT_HERO_VIDEO);

    return (
        <div className="relative grid h-dvh flex-col items-center justify-center lg:max-w-none lg:grid-cols-2 lg:px-0 pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)]">
            {/* Left panel -- immersive brand moment */}
            <div className="relative hidden flex-col lg:flex lg:min-h-full lg:w-full overflow-hidden">
                <div className="absolute inset-0 bg-[#060D18]" />

                {/* Animated gradient mesh */}
                <div
                    className="absolute inset-0 animate-pulse-slow"
                    style={{
                        opacity: 0.6,
                        background: `
                            radial-gradient(ellipse 70% 50% at 25% 25%, rgba(20, 120, 140, 0.2), transparent 70%),
                            radial-gradient(ellipse 50% 40% at 75% 75%, rgba(10, 90, 120, 0.15), transparent 70%),
                            radial-gradient(ellipse 60% 30% at 50% 50%, rgba(45, 212, 191, 0.08), transparent 70%)
                        `,
                    }}
                />

                {/* Grid pattern overlay */}
                <div
                    className="absolute inset-0 opacity-[0.03]"
                    style={{
                        backgroundImage: `
                            linear-gradient(rgba(255, 255, 255, 0.1) 1px, transparent 1px),
                            linear-gradient(90deg, rgba(255, 255, 255, 0.1) 1px, transparent 1px)
                        `,
                        backgroundSize: '60px 60px',
                    }}
                />

                {useVideo ? (
                    <>
                        <video
                            ref={videoRef}
                            src={useVideo}
                            autoPlay
                            muted
                            loop
                            playsInline
                            className="absolute inset-0 h-full w-full object-cover transition-opacity duration-1000"
                            style={{ opacity: videoReady ? 0.2 : 0 }}
                            onLoadedData={() => setVideoReady(true)}
                            onError={() => setVideoReady(false)}
                        />
                        <div className="absolute inset-0 bg-[#060D18]/70" />
                    </>
                ) : null}

                <div className="relative z-10 flex flex-1 flex-col items-center justify-center p-10 lg:p-14">
                    <FadeIn delay={0.2}>
                        <Link
                            href={home()}
                            className="focus-visible:ring-primary/50 flex flex-col items-center gap-5 rounded-2xl transition-transform duration-300 hover:scale-[1.02] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-offset-[#060D18]"
                            aria-label={`${name} — Ir al inicio`}
                        >
                            <AppLogoIcon
                                className="size-28 fill-white drop-shadow-[0_0_30px_rgba(45,212,191,0.15)] sm:size-36 lg:size-44"
                                aria-hidden
                            />
                            <span className="font-display text-xl font-bold tracking-tight text-white sm:text-2xl lg:text-3xl">
                                {name}
                            </span>
                        </Link>
                    </FadeIn>

                    <FadeIn delay={0.5}>
                        <p className="mt-8 max-w-xs text-center text-sm leading-relaxed text-white/40">
                            Sistema inteligente de monitoreo y procesamiento de alertas de flotas
                        </p>
                    </FadeIn>
                </div>

                {/* Bottom gradient fade */}
                <div className="absolute bottom-0 left-0 right-0 h-32 bg-gradient-to-t from-[#060D18] to-transparent" />
            </div>

            {/* Right panel -- form */}
            <div className="flex w-full flex-col justify-center px-6 py-10 sm:px-8 lg:p-10">
                <div className="mx-auto flex w-full max-w-[380px] flex-col justify-center space-y-6">
                    <Link
                        href={home()}
                        className="flex items-center justify-center lg:hidden"
                        aria-label={`${name} — Inicio`}
                    >
                        <AppLogoIcon className="h-11 w-auto fill-foreground sm:h-12" />
                    </Link>

                    <SlideUp delay={0.1}>
                        <header className="space-y-2">
                            <h1 className="font-display text-2xl font-bold tracking-tight text-foreground">
                                {title}
                            </h1>
                            {description ? (
                                <p className="text-sm text-muted-foreground text-balance leading-relaxed">
                                    {description}
                                </p>
                            ) : null}
                        </header>
                    </SlideUp>

                    <SlideUp delay={0.2}>
                        {children}
                    </SlideUp>
                </div>
            </div>
        </div>
    );
}
