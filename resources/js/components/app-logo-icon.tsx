import { ImgHTMLAttributes } from 'react';

export default function AppLogoIcon(props: ImgHTMLAttributes<HTMLImageElement>) {
    return (
        <img
            src="/logo.png"
            alt="SAM - Sistema Automatizado de Monitoreo"
            {...props}
        />
    );
}
