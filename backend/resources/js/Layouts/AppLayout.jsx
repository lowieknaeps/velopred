import Footer from '../Components/Footer';
import Navbar from '../Components/Navbar';

export default function AppLayout({ children }) {
    return (
        <div className="vp-shell min-h-screen">
            <div className="pointer-events-none absolute left-[-12rem] top-24 h-72 w-72 rounded-full bg-amber-300/20 blur-3xl" />
            <div className="pointer-events-none absolute right-[-10rem] top-72 h-80 w-80 rounded-full bg-teal-300/20 blur-3xl" />

            <div className="relative mx-auto flex min-h-screen w-full max-w-7xl flex-col px-4 sm:px-6 lg:px-8">
                <Navbar />
                <main className="flex-1 pb-8 pt-8 sm:pt-10">{children}</main>
                <Footer />
            </div>
        </div>
    );
}
