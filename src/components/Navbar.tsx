import { useState, useEffect } from "react";
import { Menu, X } from "lucide-react";
import { motion, AnimatePresence } from "framer-motion";
import { Link, useLocation } from "react-router-dom";
import dmLogo from "@/assets/dm-logo.jpg";

const navLinks = [
  { label: "Home", href: "#home", route: "/" },
  { label: "About", href: "#about", route: "/" },
  { label: "Services", href: "#services", route: "/" },
  { label: "Portfolio", href: "/portfolio", route: "/portfolio" },
  { label: "Process", href: "#process", route: "/" },
  { label: "Contact", href: "#contact", route: "/" },
];

const Navbar = () => {
  const [scrolled, setScrolled] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const location = useLocation();
  const isHome = location.pathname === "/";

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 40);
    window.addEventListener("scroll", onScroll);
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  const handleClick = (link: (typeof navLinks)[0]) => {
    setMobileOpen(false);

    // Portfolio is a separate page
    if (link.route === "/portfolio") return;

    if (!isHome) {
      // Navigate to home first, then scroll
      window.location.href = "/" + link.href;
      return;
    }

    const el = document.querySelector(link.href);
    el?.scrollIntoView({ behavior: "smooth" });
  };

  return (
    <header
      className={`fixed top-0 left-0 right-0 z-50 transition-all duration-300 ${
        scrolled
          ? "bg-background/90 backdrop-blur-md shadow-soft"
          : "bg-transparent"
      }`}
    >
      <div className="container mx-auto flex items-center justify-between py-3 px-6 lg:px-8">
        {/* Logo */}
        <Link to="/" className="flex items-center gap-2">
          <img
            src={dmLogo}
            alt="Digital MindFlow"
            className="w-10 h-10 rounded-lg object-contain"
          />
          <span className="font-heading text-lg font-bold tracking-tight">
            <span className="text-foreground">Digital</span>
            <span className="text-gradient-accent">MindFlow</span>
          </span>
        </Link>

        {/* Desktop nav */}
        <nav className="hidden md:flex items-center gap-7">
          {navLinks.map((link) =>
            link.route === "/portfolio" ? (
              <Link
                key={link.href}
                to="/portfolio"
                className="text-sm font-medium text-muted-foreground hover:text-foreground transition-colors duration-200"
              >
                {link.label}
              </Link>
            ) : (
              <a
                key={link.href}
                href={link.href}
                onClick={(e) => {
                  e.preventDefault();
                  handleClick(link);
                }}
                className="text-sm font-medium text-muted-foreground hover:text-foreground transition-colors duration-200"
              >
                {link.label}
              </a>
            )
          )}
          <a
            href="#contact"
            onClick={(e) => {
              e.preventDefault();
              handleClick({ label: "Contact", href: "#contact", route: "/" });
            }}
            className="inline-flex items-center justify-center rounded-lg bg-accent px-5 py-2.5 text-sm font-semibold text-accent-foreground hover:opacity-90 transition-opacity shadow-accent-glow"
          >
            Free Consultation
          </a>
        </nav>

        {/* Mobile toggle */}
        <button
          onClick={() => setMobileOpen(!mobileOpen)}
          className="md:hidden text-foreground"
          aria-label="Toggle menu"
        >
          {mobileOpen ? <X size={24} /> : <Menu size={24} />}
        </button>
      </div>

      {/* Mobile menu */}
      <AnimatePresence>
        {mobileOpen && (
          <motion.div
            initial={{ opacity: 0, height: 0 }}
            animate={{ opacity: 1, height: "auto" }}
            exit={{ opacity: 0, height: 0 }}
            className="md:hidden bg-background/95 backdrop-blur-md border-t border-border overflow-hidden"
          >
            <nav className="flex flex-col items-center gap-4 py-6">
              {navLinks.map((link) =>
                link.route === "/portfolio" ? (
                  <Link
                    key={link.href}
                    to="/portfolio"
                    onClick={() => setMobileOpen(false)}
                    className="text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
                  >
                    {link.label}
                  </Link>
                ) : (
                  <a
                    key={link.href}
                    href={link.href}
                    onClick={(e) => {
                      e.preventDefault();
                      handleClick(link);
                    }}
                    className="text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
                  >
                    {link.label}
                  </a>
                )
              )}
              <a
                href="#contact"
                onClick={(e) => {
                  e.preventDefault();
                  handleClick({ label: "Contact", href: "#contact", route: "/" });
                }}
                className="inline-flex items-center justify-center rounded-lg bg-accent px-6 py-2.5 text-sm font-semibold text-accent-foreground shadow-accent-glow"
              >
                Free Consultation
              </a>
            </nav>
          </motion.div>
        )}
      </AnimatePresence>
    </header>
  );
};

export default Navbar;
