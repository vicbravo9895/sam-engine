import { type ReactNode } from 'react';
import {
    AnimatePresence,
    motion,
    type Transition,
    type Variants,
} from 'motion/react';

const SPRING: Transition = { type: 'spring', stiffness: 260, damping: 24 };
const EASE_OUT: Transition = { duration: 0.35, ease: [0.16, 1, 0.3, 1] };

const pageVariants: Variants = {
    initial: { opacity: 0, y: 6 },
    animate: { opacity: 1, y: 0 },
    exit: { opacity: 0, y: -4 },
};

export function PageTransition({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <motion.div
            variants={pageVariants}
            initial="initial"
            animate="animate"
            exit="exit"
            transition={EASE_OUT}
            className={className}
        >
            {children}
        </motion.div>
    );
}

const staggerContainerVariants: Variants = {
    initial: {},
    animate: { transition: { staggerChildren: 0.06 } },
};

const staggerItemVariants: Variants = {
    initial: { opacity: 0, y: 10 },
    animate: { opacity: 1, y: 0 },
};

export function StaggerContainer({
    children,
    className,
    delay = 0,
}: {
    children: ReactNode;
    className?: string;
    delay?: number;
}) {
    return (
        <motion.div
            variants={staggerContainerVariants}
            initial="initial"
            animate="animate"
            transition={{ delayChildren: delay }}
            className={className}
        >
            {children}
        </motion.div>
    );
}

export function StaggerItem({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <motion.div
            variants={staggerItemVariants}
            transition={EASE_OUT}
            className={className}
        >
            {children}
        </motion.div>
    );
}

export function FadeIn({
    children,
    className,
    delay = 0,
}: {
    children: ReactNode;
    className?: string;
    delay?: number;
}) {
    return (
        <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ ...EASE_OUT, delay }}
            className={className}
        >
            {children}
        </motion.div>
    );
}

export function SlideUp({
    children,
    className,
    delay = 0,
}: {
    children: ReactNode;
    className?: string;
    delay?: number;
}) {
    return (
        <motion.div
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ ...SPRING, delay }}
            className={className}
        >
            {children}
        </motion.div>
    );
}

export function ScaleIn({
    children,
    className,
    delay = 0,
}: {
    children: ReactNode;
    className?: string;
    delay?: number;
}) {
    return (
        <motion.div
            initial={{ opacity: 0, scale: 0.95 }}
            animate={{ opacity: 1, scale: 1 }}
            transition={{ ...SPRING, delay }}
            className={className}
        >
            {children}
        </motion.div>
    );
}

export function AnimatedCounter({
    value,
    className,
    formatter,
}: {
    value: number;
    className?: string;
    formatter?: (n: number) => string;
}) {
    const format = formatter ?? ((n: number) => n.toLocaleString());

    return (
        <motion.span
            key={value}
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            transition={EASE_OUT}
            className={className}
        >
            {format(value)}
        </motion.span>
    );
}

export { AnimatePresence, motion };
