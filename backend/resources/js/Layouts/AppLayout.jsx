import Footer from '../Components/Footer';
import GlobalPageLoader from '../Components/GlobalPageLoader';
import Navbar from '../Components/Navbar';

export default function AppLayout({ children }) {
    return (
        <div className="vp-shell min-h-screen">
            <GlobalPageLoader />
            <div className="relative mx-auto flex min-h-screen w-full max-w-7xl flex-col px-4 sm:px-6 lg:px-8">
                <Navbar />
                <main className="flex-1 pb-8 pt-8 sm:pt-10">{children}</main>
                <Footer />
            </div>
        </div>
    );
}
